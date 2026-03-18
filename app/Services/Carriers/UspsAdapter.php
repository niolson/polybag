<?php

namespace App\Services\Carriers;

use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\BoxSizeType;
use App\Http\Integrations\USPS\Requests\CancelInternationalLabel;
use App\Http\Integrations\USPS\Requests\CancelLabel;
use App\Http\Integrations\USPS\Requests\InternationalLabel;
use App\Http\Integrations\USPS\Requests\Label;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Http\Integrations\USPS\USPSConnector;
use App\Models\Package;
use App\Services\SettingsService;
use Illuminate\Support\Collection;
use Saloon\Http\Response;

class UspsAdapter implements CarrierAdapterInterface
{
    public function getCarrierName(): string
    {
        return 'USPS';
    }

    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        $prepared = $this->prepareRateRequest($request, $serviceCodes);

        if (! $prepared) {
            return collect();
        }

        $connector = USPSConnector::getUspsConnector();
        $apiRequest = $this->buildRateApiRequest($request);
        $response = $connector->send($apiRequest);

        return $this->parseRateResponse($response, $request, $serviceCodes);
    }

    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
    {
        if (empty($request->packages)) {
            return null;
        }

        $connector = USPSConnector::getUspsConnector();
        $apiRequest = $this->buildRateApiRequest($request);
        $pendingRequest = $connector->createPendingRequest($apiRequest);

        return new PreparedRateRequest(
            pendingRequest: $pendingRequest,
            carrierName: 'USPS',
        );
    }

    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
    {
        if (! $response->successful()) {
            logger()->error('USPS API Error', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return collect();
        }

        $pricingOptions = $response->json('pricingOptions', []);

        if (empty($pricingOptions) || ! is_array($pricingOptions)) {
            logger()->warning('USPS API returned empty or invalid pricingOptions', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return collect();
        }

        $package = $request->packages[0];
        $results = collect();
        $totalApiRates = 0;

        foreach ($pricingOptions[0]['shippingOptions'] ?? [] as $shippingOption) {
            foreach ($shippingOption['rateOptions'] ?? [] as $rateOption) {
                $totalApiRates++;
                $rate = $rateOption['rates'][0] ?? null;

                if (! $rate) {
                    continue;
                }

                if (! $this->isValidRate($rate, $serviceCodes, $package->boxType)) {
                    continue;
                }

                $results->push(new RateResponse(
                    carrier: 'USPS',
                    serviceCode: $rate['mailClass'],
                    serviceName: $rate['description'] ?? $rate['mailClass'],
                    price: (float) ($rateOption['totalBasePrice'] ?? 0),
                    deliveryCommitment: $rateOption['commitment']['name'] ?? null,
                    deliveryDate: $rateOption['commitment']['scheduleDeliveryDate'] ?? null,
                    metadata: [
                        'mailClass' => $rate['mailClass'],
                        'processingCategory' => $rate['processingCategory'],
                        'rateIndicator' => $rate['rateIndicator'],
                        'destinationEntryFacilityType' => $rate['destinationEntryFacilityType'],
                    ],
                ));
            }
        }

        logger()->debug('USPS rate response filtering', [
            'total_api_rates' => $totalApiRates,
            'matched_rates' => $results->count(),
            'requested_codes' => $serviceCodes,
        ]);

        return $results;
    }

    /**
     * Build the USPS rate API request.
     */
    private function buildRateApiRequest(RateRequest $request): ShippingOptions
    {
        $package = $request->packages[0];
        $isInternational = $request->destinationCountry !== 'US';

        $body = [
            'pricingOptions' => [
                [
                    'priceType' => 'CONTRACT',
                    'paymentAccount' => [
                        'accountType' => 'EPS',
                        'accountNumber' => app(SettingsService::class)->get('usps.crid', config('services.usps.crid')),
                    ],
                ],
            ],
            'originZIPCode' => $request->originPostalCode,
            'packageDescription' => [
                'weight' => $package->weight,
                'length' => $package->length,
                'width' => $package->width,
                'height' => $package->height,
                'mailClass' => $isInternational ? 'ALL' : 'ALL_OUTBOUND',
                'mailingDate' => $request->shipDate?->format('Y-m-d') ?? date('Y-m-d'),
            ],
        ];
        if (! $isInternational) {
            $body['destinationZIPCode'] = $request->destinationPostalCode;
        }

        if ($isInternational) {
            $body['destinationCountryCode'] = $request->destinationCountry;
        }

        $apiRequest = new ShippingOptions;
        $apiRequest->body()->set($body);

        return $apiRequest;
    }

    public function createShipment(ShipRequest $request): ShipResponse
    {
        $isInternational = $request->toAddress->country !== 'US';

        return $isInternational
            ? $this->createInternationalShipment($request)
            : $this->createDomesticShipment($request);
    }

    private function createDomesticShipment(ShipRequest $request): ShipResponse
    {
        try {
            $connector = USPSConnector::getUspsConnector();
            $paymentAuthorizationToken = USPSConnector::getUspsPaymentAuthorizationToken();

            $apiRequest = new Label;
            $apiRequest->headers()->set([
                'X-Payment-Authorization-Token' => $paymentAuthorizationToken,
            ]);

            $toAddress = $this->buildDomesticAddress($request->toAddress);
            $fromAddress = $this->buildDomesticAddress($request->fromAddress);

            $metadata = $request->selectedRate->metadata;

            $imageInfo = [
                'receiptOption' => 'NONE',
            ];

            if ($request->labelFormat === 'zpl') {
                $imageInfo['imageType'] = $request->labelDpi === 300 ? 'ZPL300DPI' : 'ZPL203DPI';
            }

            $apiRequest->body()->set([
                'toAddress' => $toAddress,
                'fromAddress' => $fromAddress,
                'packageDescription' => [
                    'mailClass' => $metadata['mailClass'],
                    'rateIndicator' => $metadata['rateIndicator'],
                    'weightUOM' => 'lb',
                    'weight' => $request->packageData->weight,
                    'dimensionsUOM' => 'in',
                    'length' => $request->packageData->length,
                    'height' => $request->packageData->height,
                    'width' => $request->packageData->width,
                    'processingCategory' => $metadata['processingCategory'],
                    'mailingDate' => $request->shipDate?->format('Y-m-d') ?? date('Y-m-d'),
                    'extraServices' => [],
                    'destinationEntryFacilityType' => 'NONE',
                ],
                'imageInfo' => $imageInfo,
            ]);

            $response = $connector->send($apiRequest);

            if (! $response->successful()) {
                $errorMessage = $response->json('error.message') ?? $response->json('message') ?? 'Unknown USPS error';
                logger()->error('USPS createDomesticShipment API error', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                    'body' => $response->json(),
                ]);

                return ShipResponse::failure($errorMessage);
            }

            $response->parseBody();

            // Validate required response fields
            if (empty($response->metadata['trackingNumber'])) {
                logger()->error('USPS createDomesticShipment missing tracking number', [
                    'metadata' => $response->metadata,
                ]);

                return ShipResponse::failure('USPS response missing tracking number');
            }

            if (empty($response->label)) {
                logger()->error('USPS createDomesticShipment missing label data', [
                    'metadata' => $response->metadata,
                ]);

                return ShipResponse::failure('USPS response missing label data');
            }

            return ShipResponse::success(
                trackingNumber: $response->metadata['trackingNumber'],
                cost: (float) ($response->metadata['postage'] ?? $request->selectedRate->price),
                carrier: 'USPS',
                service: $request->selectedRate->serviceName,
                labelData: $response->label,
                labelFormat: $request->labelFormat,
                labelDpi: $request->labelDpi,
                shipDate: $request->shipDate,
            );
        } catch (\Exception $e) {
            logger()->error('USPS createDomesticShipment error', ['error' => $e->getMessage()]);

            return ShipResponse::failure($e->getMessage());
        }
    }

    private function createInternationalShipment(ShipRequest $request): ShipResponse
    {
        try {
            $connector = USPSConnector::getUspsConnector();
            $paymentAuthorizationToken = USPSConnector::getUspsPaymentAuthorizationToken();

            $apiRequest = new InternationalLabel;
            $apiRequest->headers()->set([
                'X-Payment-Authorization-Token' => $paymentAuthorizationToken,
            ]);

            $toAddress = $this->buildInternationalAddress($request->toAddress);
            $fromAddress = $this->buildDomesticAddress($request->fromAddress);

            $metadata = $request->selectedRate->metadata;

            $imageInfo = [
                'receiptOption' => 'NONE',
            ];

            if ($request->labelFormat === 'zpl') {
                $imageInfo['imageType'] = $request->labelDpi === 300 ? 'ZPL300DPI' : 'ZPL203DPI';
            }

            $apiRequest->body()->set([
                'toAddress' => $toAddress,
                'fromAddress' => $fromAddress,
                'packageDescription' => [
                    'mailClass' => $metadata['mailClass'],
                    'rateIndicator' => $metadata['rateIndicator'],
                    'weightUOM' => 'lb',
                    'weight' => $request->packageData->weight,
                    'dimensionsUOM' => 'in',
                    'length' => $request->packageData->length,
                    'height' => $request->packageData->height,
                    'width' => $request->packageData->width,
                    'processingCategory' => $metadata['processingCategory'],
                    'mailingDate' => $request->shipDate?->format('Y-m-d') ?? date('Y-m-d'),
                    'extraServices' => [],
                    'destinationEntryFacilityType' => $metadata['destinationEntryFacilityType'] ?? 'INTERNATIONAL_SERVICE_CENTER',
                ],
                'customsForm' => $this->buildCustomsForm($request),
                'imageInfo' => $imageInfo,
            ]);

            $response = $connector->send($apiRequest);

            if (! $response->successful()) {
                $errorMessage = $response->json('error.message') ?? $response->json('message') ?? 'Unknown USPS error';
                logger()->error('USPS createInternationalShipment API error', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                    'body' => $response->json(),
                ]);

                return ShipResponse::failure($errorMessage);
            }

            $response->parseBody();

            // International responses use 'internationalTrackingNumber' instead of 'trackingNumber'
            $trackingNumber = $response->metadata['internationalTrackingNumber']
                ?? $response->metadata['trackingNumber']
                ?? null;

            // Validate required response fields
            if (empty($trackingNumber)) {
                logger()->error('USPS createInternationalShipment missing tracking number', [
                    'metadata' => $response->metadata,
                ]);

                return ShipResponse::failure('USPS response missing tracking number');
            }

            if (empty($response->label)) {
                logger()->error('USPS createInternationalShipment missing label data', [
                    'metadata' => $response->metadata,
                ]);

                return ShipResponse::failure('USPS response missing label data');
            }

            return ShipResponse::success(
                trackingNumber: $trackingNumber,
                cost: (float) ($response->metadata['postage'] ?? $request->selectedRate->price),
                carrier: 'USPS',
                service: $request->selectedRate->serviceName,
                labelData: $response->label,
                labelOrientation: 'landscape',
                labelFormat: $request->labelFormat,
                labelDpi: $request->labelDpi,
                shipDate: $request->shipDate,
            );
        } catch (\Exception $e) {
            logger()->error('USPS createInternationalShipment error', ['error' => $e->getMessage()]);

            return ShipResponse::failure($e->getMessage());
        }
    }

    /**
     * Build the customs form for international shipments.
     *
     * @return array<string, mixed>
     */
    private function buildCustomsForm(ShipRequest $request): array
    {
        $contents = [];

        foreach ($request->customsItems as $item) {
            $contentItem = [
                'itemDescription' => mb_substr($item->description, 0, 30),
                'itemQuantity' => $item->quantity,
                'itemTotalValue' => round($item->unitValue * $item->quantity, 2),
                'weightUOM' => 'lb',
                'itemTotalWeight' => round($item->weight * $item->quantity, 4),
                'countryofOrigin' => $item->countryOfOrigin ?? 'US',
            ];

            if ($item->hsTariffNumber) {
                $contentItem['HSTariffNumber'] = $item->hsTariffNumber;
            }

            $contents[] = $contentItem;
        }

        return [
            'AESITN' => 'NO EEI 30.37(a)',
            'customsContentType' => 'MERCHANDISE',
            'contents' => $contents,
        ];
    }

    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
    {
        try {
            $connector = USPSConnector::getUspsConnector();
            $paymentAuthorizationToken = USPSConnector::getUspsPaymentAuthorizationToken();
            $isInternational = $package->shipment->country !== 'US';

            $apiRequest = $isInternational
                ? new CancelInternationalLabel($trackingNumber)
                : new CancelLabel($trackingNumber);

            $apiRequest->headers()->set([
                'X-Payment-Authorization-Token' => $paymentAuthorizationToken,
            ]);

            $response = $connector->send($apiRequest);

            if ($response->successful()) {
                return CancelResponse::success('Label voided successfully.');
            }

            return CancelResponse::failure('USPS returned status '.$response->status());
        } catch (\Exception $e) {
            return CancelResponse::failure($e->getMessage());
        }
    }

    public function isConfigured(): bool
    {
        return ! empty(app(SettingsService::class)->get('usps.client_id', config('services.usps.client_id')))
            && ! empty(app(SettingsService::class)->get('usps.client_secret', config('services.usps.client_secret')))
            && ! empty(app(SettingsService::class)->get('usps.crid', config('services.usps.crid')));
    }

    public function supportsMultiPackage(): bool
    {
        return false;
    }

    public function supportsManifest(): bool
    {
        return true;
    }

    public function resolvePreSelectedRate(RateResponse $rate, Package $package): RateResponse
    {
        $rateRequest = RateRequest::fromPackage($package);
        $rates = $this->getRates($rateRequest, [$rate->serviceCode]);

        if ($rates->isEmpty()) {
            return $rate;
        }

        return $rates->sortBy('price')->first();
    }

    /**
     * Rate indicators valid for all package types.
     */
    private const UNIVERSAL_RATE_INDICATORS = ['SP', 'PA'];

    /**
     * Rate indicators valid only for boxes (non-soft pack).
     */
    private const BOX_RATE_INDICATORS = ['CP'];

    /**
     * Rate indicators valid only for soft pack (polybags, padded mailers).
     * Cubic Soft Pack Tiers 1-10.
     */
    private const SOFT_PACK_RATE_INDICATORS = ['P5', 'P6', 'P7', 'P8', 'P9', 'Q6', 'Q7', 'Q8', 'Q9', 'Q0'];

    /**
     * Check if a rate is valid based on filtering criteria.
     *
     * @param  array<string, mixed>  $rate
     * @param  array<string>  $serviceCodes
     */
    private function isValidRate(array $rate, array $serviceCodes, ?BoxSizeType $boxType = null): bool
    {
        // Filter out non-applicable processing categories
        if (in_array($rate['processingCategory'], ['CARDS', 'LETTERS', 'FLATS', 'OPEN_AND_DISTRIBUTE'])) {
            return false;
        }

        // Filter out library and media mail
        if (in_array($rate['mailClass'], ['LIBRARY_MAIL', 'MEDIA_MAIL'])) {
            return false;
        }

        // Only include requested service codes (empty means all)
        if (! empty($serviceCodes) && ! in_array($rate['mailClass'], $serviceCodes)) {
            return false;
        }

        // Filter rate indicators based on box type
        if (! $this->isValidRateIndicator($rate['rateIndicator'], $boxType)) {
            return false;
        }

        // Only include direct-to-destination rates (NONE for domestic, INTERNATIONAL_SERVICE_CENTER for international)
        if (! in_array($rate['destinationEntryFacilityType'], ['NONE', 'INTERNATIONAL_SERVICE_CENTER'])) {
            return false;
        }

        return true;
    }

    /**
     * Check if a rate indicator is valid for the given box type.
     */
    private function isValidRateIndicator(string $rateIndicator, ?BoxSizeType $boxType): bool
    {
        // Universal rate indicators are always valid
        if (in_array($rateIndicator, self::UNIVERSAL_RATE_INDICATORS)) {
            return true;
        }

        // If no box type specified, allow all known rate indicators (backwards compatibility)
        if ($boxType === null) {
            return in_array($rateIndicator, [
                ...self::UNIVERSAL_RATE_INDICATORS,
                ...self::BOX_RATE_INDICATORS,
                ...self::SOFT_PACK_RATE_INDICATORS,
            ]);
        }

        // Soft pack types (polybag, padded mailer) can use soft pack rate indicators
        if (in_array($boxType, [BoxSizeType::POLYBAG, BoxSizeType::PADDED_MAILER])) {
            return in_array($rateIndicator, self::SOFT_PACK_RATE_INDICATORS);
        }

        // Box type can use box rate indicators
        if ($boxType === BoxSizeType::BOX) {
            return in_array($rateIndicator, self::BOX_RATE_INDICATORS);
        }

        return false;
    }

    /**
     * Build USPS domestic address array from AddressData DTO.
     *
     * @return array<string, string>
     */
    private function buildDomesticAddress(\App\DataTransferObjects\Shipping\AddressData $address): array
    {
        $result = [
            'streetAddress' => mb_substr($address->streetAddress, 0, 50),
            'city' => mb_substr($address->city, 0, 28),
            'state' => $address->stateOrProvince,
            'ZIPCode' => substr($address->postalCode, 0, 5),
        ];

        $this->addNameFields($result, $address);

        if ($address->streetAddress2) {
            $result['secondaryAddress'] = mb_substr($address->streetAddress2, 0, 50);
        }

        return $result;
    }

    /**
     * Build USPS international address array from AddressData DTO.
     *
     * @return array<string, string>
     */
    private function buildInternationalAddress(\App\DataTransferObjects\Shipping\AddressData $address): array
    {
        $result = [
            'streetAddress' => mb_substr($address->streetAddress, 0, 50),
            'city' => mb_substr($address->city, 0, 30),
            'country' => $address->country,
            'countryISOAlpha2Code' => $address->country,
        ];

        $this->addNameFields($result, $address);

        if ($address->stateOrProvince) {
            $result['province'] = mb_substr($address->stateOrProvince, 0, 30);
        }

        if ($address->postalCode) {
            $result['postalCode'] = mb_substr($address->postalCode, 0, 12);
        }

        if ($address->streetAddress2) {
            $result['secondaryAddress'] = mb_substr($address->streetAddress2, 0, 50);
        }

        return $result;
    }

    /**
     * Add name fields to a USPS address array.
     * USPS requires (firstName + lastName) or firm. When only one name is
     * provided, use it as the firm name instead.
     *
     * TODO: Evaluate whether using a placeholder (e.g. ".") in the missing
     * firstName/lastName field would produce better label output than using
     * the firm field as a fallback. The firm approach works but may display
     * differently on the printed label.
     *
     * @param  array<string, string>  $result
     */
    private function addNameFields(array &$result, \App\DataTransferObjects\Shipping\AddressData $address): void
    {
        $hasFirst = (bool) $address->firstName;
        $hasLast = (bool) $address->lastName;

        if ($hasFirst && $hasLast) {
            $result['firstName'] = mb_substr($address->firstName, 0, 30);
            $result['lastName'] = mb_substr($address->lastName, 0, 30);
        } elseif ($hasFirst || $hasLast) {
            // Only one name — use firm field so USPS doesn't reject it
            $name = $hasFirst ? $address->firstName : $address->lastName;
            $result['firm'] = mb_substr($name, 0, 38);
        }

        if ($address->company) {
            $result['firm'] = mb_substr($address->company, 0, 38);
        }
    }
}
