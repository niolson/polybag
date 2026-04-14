<?php

namespace App\Services\Carriers;

use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\DataTransferObjects\Tracking\TrackingEventData;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\BoxSizeType;
use App\Enums\ServiceCapability;
use App\Enums\TrackingStatus;
use App\Http\Integrations\USPS\Requests\CancelInternationalLabel;
use App\Http\Integrations\USPS\Requests\CancelLabel;
use App\Http\Integrations\USPS\Requests\InternationalLabel;
use App\Http\Integrations\USPS\Requests\Label;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Http\Integrations\USPS\Requests\TrackShipment;
use App\Http\Integrations\USPS\USPSConnector;
use App\Models\Package;
use App\Services\Carriers\Concerns\HasDefaultServiceCapabilities;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\Statuses\ForbiddenException;
use Saloon\Http\Response;

class UspsAdapter implements CarrierAdapterInterface
{
    use HasDefaultServiceCapabilities;

    public function serviceCapability(string $serviceCode): ServiceCapability
    {
        return match ($serviceCode) {
            'cremated_remains' => ServiceCapability::Supported,
            // Mailing alcohol is prohibited under 27 CFR 72.11 (federal law)
            'alcohol' => ServiceCapability::Prohibited,
            // USPS uses air transport for express services; ground-only battery shipments cannot be guaranteed
            'lithium_battery_ground_only' => ServiceCapability::Prohibited,
            default => ServiceCapability::NotImplemented,
        };
    }

    public function getCarrierName(): string
    {
        return 'USPS';
    }

    /**
     * Cache key for the USPS pricing type (CONTRACT or RETAIL).
     * Falls back to RETAIL and caches that if the account lacks EPS contract access.
     */
    private const PRICING_TYPE_CACHE_KEY = 'usps_pricing_type';

    private function getPricingType(): string
    {
        return Cache::get(self::PRICING_TYPE_CACHE_KEY, 'CONTRACT');
    }

    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        if (empty($request->packages)) {
            return collect();
        }

        $connector = USPSConnector::getUspsConnector();
        $apiRequest = $this->buildRateApiRequest($request);

        try {
            $response = $connector->send($apiRequest);
        } catch (ForbiddenException $e) {
            if ($this->getPricingType() === 'CONTRACT') {
                logger()->warning('USPS CONTRACT pricing returned 403 — falling back to RETAIL and retrying');
                Cache::put(self::PRICING_TYPE_CACHE_KEY, 'RETAIL', now()->addDays(7));
                $apiRequest = $this->buildRateApiRequest($request);
                $response = $connector->send($apiRequest);
            } else {
                throw $e;
            }
        }

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

        $pricingType = $this->getPricingType();
        $pricingOption = ['priceType' => $pricingType];

        if ($pricingType === 'CONTRACT') {
            $settings = app(SettingsService::class);
            $pricingOption['paymentAccount'] = [
                'accountType' => 'EPS',
                'accountNumber' => $settings->get('usps.eps_account', $settings->get('usps.crid')),
            ];
        }

        $body = [
            'pricingOptions' => [$pricingOption],
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

    public function supportsTracking(): bool
    {
        return true;
    }

    public function trackShipment(Package $package): TrackShipmentResponse
    {
        try {
            $connector = USPSConnector::getUspsConnector();
            $trackRequest = new TrackShipment($package->tracking_number);
            $requestUri = rtrim($connector->resolveBaseUrl(), '/').$trackRequest->resolveEndpoint();

            Log::channel('usps-validation')->info('TRACK REQUEST', [
                'tracking_number' => $package->tracking_number,
                'uri' => $requestUri,
                'payload' => $trackRequest->body()->all(),
            ]);

            $response = $connector->send($trackRequest);
            $rawResponse = $this->decodeJsonSafely($response);

            Log::channel('usps-validation')->info('TRACK RESPONSE', [
                'tracking_number' => $package->tracking_number,
                'uri' => $requestUri,
                'status' => $response->status(),
                'body' => $rawResponse,
            ]);

            if (! $response->successful()) {
                return TrackShipmentResponse::failure(
                    data_get($rawResponse, 'error.message')
                        ?? data_get($rawResponse, 'message')
                        ?? 'USPS tracking request failed.',
                    ['raw' => $rawResponse],
                );
            }

            $trackingDetails = collect($rawResponse)
                ->filter(fn ($detail) => is_array($detail))
                ->values();

            $trackingDetail = $trackingDetails->first();

            if (! is_array($trackingDetail)) {
                return TrackShipmentResponse::failure('USPS returned an unexpected tracking response.', [
                    'raw' => $rawResponse,
                ]);
            }

            $statusLabel = $trackingDetail['statusSummary']
                ?? $trackingDetail['status']
                ?? $trackingDetail['statusCategory']
                ?? 'Tracking update available';

            $events = collect($trackingDetail['trackingEvents'] ?? [])
                ->filter(fn ($event) => is_array($event))
                ->map(fn (array $event): TrackingEventData => $this->mapTrackingEvent($event))
                ->sortByDesc(fn (TrackingEventData $event) => $event->timestamp?->getTimestamp() ?? 0)
                ->values()
                ->all();

            $estimatedDeliveryAt = $this->parseUspsEstimatedDelivery($trackingDetail);
            $deliveredAt = $this->resolveDeliveredAt($events, $trackingDetail);
            $status = $this->mapTrackingStatus($trackingDetail, $events);

            return TrackShipmentResponse::success(
                status: $status,
                events: $events,
                estimatedDeliveryAt: $estimatedDeliveryAt,
                deliveredAt: $deliveredAt,
                statusLabel: $statusLabel,
                details: [
                    'raw' => $rawResponse,
                ],
            );
        } catch (RequestException $e) {
            $rawResponse = $this->decodeJsonSafely($e->getResponse());

            Log::channel('usps-validation')->info('TRACK RESPONSE', [
                'tracking_number' => $package->tracking_number,
                'uri' => rtrim(USPSConnector::getUspsConnector()->resolveBaseUrl(), '/').(new TrackShipment($package->tracking_number))->resolveEndpoint(),
                'status' => $e->getResponse()->status(),
                'body' => $rawResponse,
            ]);

            return TrackShipmentResponse::failure(
                data_get($rawResponse, 'error.message')
                    ?? data_get($rawResponse, 'message')
                    ?? $e->getMessage()
                    ?? 'USPS tracking request failed.',
                ['raw' => $rawResponse],
            );
        } catch (\Throwable $e) {
            logger()->error('USPS trackShipment error', [
                'tracking_number' => $package->tracking_number,
                'error' => $e->getMessage(),
            ]);

            return TrackShipmentResponse::failure('Unable to fetch USPS tracking information.');
        }
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
        $settings = app(SettingsService::class);

        return filled($settings->get('usps.client_id'))
            && filled($settings->get('usps.client_secret'))
            && filled($settings->get('usps.crid'));
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
     * @return array<int|string, mixed>
     */
    private function decodeJsonSafely(Response $response): array
    {
        try {
            $decoded = $response->json();

            return is_array($decoded)
                ? $decoded
                : ['body' => $response->body()];
        } catch (\JsonException) {
            return ['body' => $response->body()];
        }
    }

    /**
     * @param  array<string, mixed>  $trackingDetail
     * @param  array<int, TrackingEventData>  $events
     */
    private function mapTrackingStatus(array $trackingDetail, array $events): TrackingStatus
    {
        $statusText = strtoupper(implode(' ', array_filter([
            $trackingDetail['status'] ?? null,
            $trackingDetail['statusCategory'] ?? null,
            $trackingDetail['statusSummary'] ?? null,
        ])));

        if (
            str_contains($statusText, 'DELIVERED')
            || str_contains($statusText, 'DELIVERY CONFIRMED')
        ) {
            return TrackingStatus::Delivered;
        }

        if (str_contains($statusText, 'OUT FOR DELIVERY')) {
            return TrackingStatus::OutForDelivery;
        }

        if (str_contains($statusText, 'RETURN')) {
            return TrackingStatus::Returned;
        }

        if (
            str_contains($statusText, 'EXCEPTION')
            || str_contains($statusText, 'DELAY')
            || str_contains($statusText, 'ALERT')
            || str_contains($statusText, 'HOLD')
            || str_contains($statusText, 'PICKUP')
            || str_contains($statusText, 'NO ACCESS')
            || str_contains($statusText, 'UNCLAIMED')
            || str_contains($statusText, 'ACTION NEEDED')
        ) {
            return TrackingStatus::Exception;
        }

        if (
            str_contains($statusText, 'PRE-SHIPMENT')
            || str_contains($statusText, 'PRE SHIPMENT')
            || str_contains($statusText, 'LABEL CREATED')
            || str_contains($statusText, 'SHIPPING LABEL CREATED')
        ) {
            return TrackingStatus::PreTransit;
        }

        if (! empty($events)) {
            return TrackingStatus::InTransit;
        }

        return TrackingStatus::PreTransit;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function mapTrackingEvent(array $event): TrackingEventData
    {
        $locationParts = array_filter([
            $event['eventCity'] ?? null,
            $event['eventState'] ?? null,
            $event['eventCountry'] ?? null,
        ]);

        return new TrackingEventData(
            timestamp: $this->parseUspsEventTimestamp($event),
            location: empty($locationParts) ? null : implode(', ', $locationParts),
            description: $event['eventType']
                ?? $event['status']
                ?? 'Tracking event',
            statusCode: $event['eventCode'] ?? null,
            status: $event['actionCode'] ?? null,
            raw: $event,
        );
    }

    /**
     * @param  array<string, mixed>  $trackingDetail
     */
    private function parseUspsEstimatedDelivery(array $trackingDetail): ?CarbonImmutable
    {
        $expectation = $trackingDetail['deliveryDateExpectation'] ?? [];

        if (! is_array($expectation)) {
            return null;
        }

        $date = $expectation['predictedDeliveryDate']
            ?? $expectation['expectedDeliveryDate']
            ?? $expectation['guaranteedDeliveryDate']
            ?? null;

        $endTime = $expectation['predictedDeliveryWindowEndTime']
            ?? $expectation['endOfDay']
            ?? null;

        if (! is_string($date) || blank($date)) {
            return null;
        }

        $dateTime = $date;

        if (is_string($endTime) && filled($endTime) && ! str_contains($date, 'T')) {
            $dateTime = "{$date} {$endTime}";
        }

        try {
            return CarbonImmutable::parse($dateTime);
        } catch (\Throwable) {
            try {
                return CarbonImmutable::parse($date);
            } catch (\Throwable) {
                return null;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function parseUspsEventTimestamp(array $event): ?CarbonImmutable
    {
        $timestamp = $event['GMTTimestamp']
            ?? $event['eventTimestamp']
            ?? null;

        if (! is_string($timestamp) || blank($timestamp)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($timestamp);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, TrackingEventData>  $events
     * @param  array<string, mixed>  $trackingDetail
     */
    private function resolveDeliveredAt(array $events, array $trackingDetail): ?CarbonImmutable
    {
        $deliveredEvent = collect($events)->first(function (TrackingEventData $event): bool {
            $description = strtoupper($event->description);
            $statusCode = strtoupper((string) $event->statusCode);

            return str_contains($description, 'DELIVER')
                || in_array($statusCode, ['01', 'DELIVERED'], true);
        });

        if ($deliveredEvent instanceof TrackingEventData) {
            return $deliveredEvent->timestamp;
        }

        $statusText = strtoupper(implode(' ', array_filter([
            $trackingDetail['status'] ?? null,
            $trackingDetail['statusCategory'] ?? null,
            $trackingDetail['statusSummary'] ?? null,
        ])));

        return null;
    }

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
    private function buildDomesticAddress(AddressData $address): array
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
    private function buildInternationalAddress(AddressData $address): array
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
    private function addNameFields(array &$result, AddressData $address): void
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
