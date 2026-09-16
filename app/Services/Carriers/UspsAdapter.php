<?php

namespace App\Services\Carriers;

use App\Contracts\DirectCarrierAdapter;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\DataTransferObjects\Tracking\TrackingEventData;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Enums\ServiceCapability;
use App\Enums\TrackingStatus;
use App\Exceptions\Carriers\UnclassifiablePackagingException;
use App\Http\Integrations\USPS\Requests\CancelInternationalLabel;
use App\Http\Integrations\USPS\Requests\CancelLabel;
use App\Http\Integrations\USPS\Requests\InternationalLabel;
use App\Http\Integrations\USPS\Requests\Label;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Http\Integrations\USPS\Requests\TrackShipment;
use App\Http\Integrations\USPS\USPSConnector;
use App\Models\CarrierAccount;
use App\Models\Package;
use App\Services\Carriers\Concerns\BuildsCustomerReferences;
use App\Services\Carriers\Concerns\ConsultsCarrierPolicyForOffers;
use App\Services\Carriers\Concerns\DecodesJsonResponses;
use App\Services\Carriers\Concerns\HasDefaultServiceCapabilities;
use App\Services\Carriers\Concerns\ResolvesCarrierAccount;
use App\Services\Carriers\Concerns\ResolvesDeliveredAt;
use App\Services\Shipping\PackagingFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\Statuses\ForbiddenException;
use Saloon\Http\Response;

class UspsAdapter implements DirectCarrierAdapter
{
    use BuildsCustomerReferences;
    use ConsultsCarrierPolicyForOffers;
    use DecodesJsonResponses;
    use HasDefaultServiceCapabilities;
    use ResolvesCarrierAccount;
    use ResolvesDeliveredAt;

    public function serviceCapability(string $serviceCode): ServiceCapability
    {
        return match ($serviceCode) {
            // USPS delivers Saturday as part of standard service — no special flag needed
            'saturday_delivery' => ServiceCapability::Supported,
            'cremated_remains' => ServiceCapability::Supported,
            'signature_required' => ServiceCapability::Supported,
            'adult_signature_required' => ServiceCapability::Supported,
            'declared_value' => ServiceCapability::Supported,
            'lithium_battery_in_equipment' => ServiceCapability::Supported,
            'lithium_battery_standalone' => ServiceCapability::Supported,
            // Surface-only per Pub 52 — carrier-service scope rows restrict to ground mail classes
            'lithium_battery_ground_only' => ServiceCapability::Supported,
            // Mailing alcohol is prohibited under 27 CFR 72.11 (federal law)
            'alcohol' => ServiceCapability::Prohibited,
            default => ServiceCapability::NotImplemented,
        };
    }

    /**
     * USPS insurance (extra services 930/931) covers up to $5,000 declared value.
     */
    public function declaredValueCap(): ?float
    {
        return 5000.0;
    }

    /**
     * Extra service codes accepted by the international label endpoint — a
     * narrower enum than domestic (insurance and intl-valid lithium only).
     *
     * @var array<int, int>
     */
    private const INTERNATIONAL_EXTRA_SERVICES = [930, 931, 820];

    /**
     * Plain-language equivalents for USPS label API error codes.
     *
     * The raw response is a wall of JSON, and its `message` is usually just
     * "Bad Request" with the real reason buried in `error.errors[].detail`.
     * A packer needs to know whether to fix the address, re-weigh the box, or
     * call someone — the full payload still goes to the usps-validation log.
     *
     * @var array<array-key, string>
     */
    private const LABEL_ERROR_MESSAGES = [
        '160021' => 'The customs item weights add up to more than the package weight. Re-weigh the package, or confirm the customs weight override.',
        '160138' => 'USPS reports this destination ZIP Code is no longer in service. Check the address with the customer.',
        '160140' => 'USPS requires customs details for this destination. The package needs scanned items before a label can be bought.',
    ];

    /**
     * USPS prints a reference in the label's reference block only when the entry
     * asks for it, and only on a 4X6/4X5/6X4 domestic label — a label carrying a
     * customs form shows nothing, which is what buildCustomsForm()'s
     * invoiceNumber is for. referenceNumber is documented as 1..30 characters.
     *
     * @return array<string, mixed>
     */
    private function buildCustomerReference(ShipRequest $request): array
    {
        $references = $this->labelReferences($request, maxLength: 30, maxCount: 2);

        if ($references === []) {
            return [];
        }

        return [
            'customerReference' => array_map(fn (string $reference): array => [
                'referenceNumber' => $reference,
                'printReferenceNumber' => true,
            ], $references),
        ];
    }

    /**
     * Map resolved special service codes to USPS numeric extra services plus
     * the companion fields the Labels API requires alongside them.
     *
     * @param  array<int, string>  $codes
     * @param  array<string, array<string, mixed>>  $config
     * @return array{extraServices: array<int, int>, packageValue: float|null, packageOptions: array<string, mixed>, hazmat: bool, appliedCodes: array<int, string>}
     */
    private function mapExtraServices(array $codes, array $config, bool $isInternational): array
    {
        $declaredAmount = (float) ($config['declared_value']['amount'] ?? 0);

        $extraServices = [];
        $appliedCodes = [];

        foreach ($codes as $code) {
            $numeric = match ($code) {
                'signature_required' => 921,
                'adult_signature_required' => 922,
                // 930 auto-upgrades to 931 above $500 — send the right code up front
                'declared_value' => $declaredAmount > 500 ? 931 : 930,
                'lithium_battery_in_equipment' => 818,
                'lithium_battery_standalone' => 820,
                'lithium_battery_ground_only' => 816,
                default => null,
            };

            if ($numeric === null) {
                continue;
            }

            if ($isInternational && ! in_array($numeric, self::INTERNATIONAL_EXTRA_SERVICES, true)) {
                continue;
            }

            $extraServices[] = $numeric;
            $appliedCodes[] = $code;
        }

        $hasInsurance = array_intersect($extraServices, [930, 931]) !== [];
        // 921/922/931 accept the physicalSignatureRequired field; false allows eSOL.
        // Sandbox-verified 2026-07-09: these live in packageDescription.packageOptions
        // on the label APIs (packageValue is silently unread anywhere else), while the
        // rating API reads packageValue directly on its packageDescription.
        $needsSignatureField = array_intersect($extraServices, [921, 922, 931]) !== [];

        $packageOptions = [
            ...($hasInsurance ? ['packageValue' => round($declaredAmount, 2)] : []),
            ...($needsSignatureField ? ['physicalSignatureRequired' => false] : []),
        ];

        return [
            'extraServices' => $extraServices,
            'packageValue' => $hasInsurance ? round($declaredAmount, 2) : null,
            'packageOptions' => $packageOptions,
            'hazmat' => array_intersect($extraServices, [816, 818, 820]) !== [],
            'appliedCodes' => $appliedCodes,
        ];
    }

    public function getCarrierName(): string
    {
        return 'USPS';
    }

    /**
     * Cache key prefix for the USPS pricing type (CONTRACT or RETAIL).
     * Falls back to RETAIL and caches that if the account lacks EPS contract access.
     */
    private const PRICING_TYPE_CACHE_KEY = 'usps_pricing_type';

    /**
     * Per-account cache key for the resolved pricing type. Scoping by account keeps
     * one account's RETAIL fallback from poisoning the tier shown for another.
     */
    private function pricingTypeCacheKey(?CarrierAccount $account): string
    {
        return $account ? self::PRICING_TYPE_CACHE_KEY.":{$account->id}" : self::PRICING_TYPE_CACHE_KEY;
    }

    private function getPricingType(?CarrierAccount $account = null): string
    {
        return Cache::get($this->pricingTypeCacheKey($account), 'CONTRACT');
    }

    /**
     * Read the last detected pricing type for an account without probing the API.
     * Returns 'CONTRACT', 'RETAIL', or null when the account has never been tested.
     */
    public function cachedPricingType(CarrierAccount $account): ?string
    {
        return Cache::get($this->pricingTypeCacheKey($account));
    }

    /**
     * Probe whether an account has USPS CONTRACT (negotiated) pricing access.
     *
     * Authenticates with the account's saved credentials (OAuth or client credentials),
     * sends a CONTRACT rate probe, and caches the result per account. Returns 'CONTRACT'
     * when negotiated rates are available or 'RETAIL' when the account lacks EPS contract
     * access (403). Authentication failures and other transport errors are thrown so the
     * caller can distinguish "no contract" from "credentials broken".
     */
    public function detectPricingType(CarrierAccount $account): string
    {
        $connector = USPSConnector::getAuthenticatedConnector($account);

        $request = new ShippingOptions;
        $request->body()->set([
            'pricingOptions' => [[
                'priceType' => 'CONTRACT',
                'paymentAccount' => [
                    'accountType' => 'EPS',
                    'accountNumber' => $account->credential('eps_account') ?? $account->credential('crid'),
                ],
            ]],
            'originZIPCode' => '90210',
            'destinationZIPCode' => '10001',
            'packageDescription' => [
                'weight' => 1.0,
                'length' => 10,
                'width' => 8,
                'height' => 4,
                'mailClass' => 'ALL_OUTBOUND',
                'mailingDate' => date('Y-m-d'),
            ],
        ]);

        try {
            $connector->send($request);
            $pricingType = 'CONTRACT';
        } catch (ForbiddenException) {
            $pricingType = 'RETAIL';
        }

        Cache::put($this->pricingTypeCacheKey($account), $pricingType, now()->addDays(7));

        return $pricingType;
    }

    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        if (empty($request->packages)) {
            return collect();
        }

        $account = $this->resolveAccount($request->locationId, $request->clientId);
        $connector = USPSConnector::getAuthenticatedConnector($account);
        $apiRequest = $this->buildRateApiRequest($request, $account);

        try {
            $response = $connector->send($apiRequest);
        } catch (ForbiddenException $e) {
            if ($this->getPricingType($account) === 'CONTRACT') {
                logger()->warning('USPS CONTRACT pricing returned 403 — falling back to RETAIL and retrying');
                Cache::put($this->pricingTypeCacheKey($account), 'RETAIL', now()->addDays(7));
                $apiRequest = $this->buildRateApiRequest($request, $account);
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

        $account = $this->resolveAccount($request->locationId, $request->clientId);
        $connector = USPSConnector::getAuthenticatedConnector($account);
        $apiRequest = $this->buildRateApiRequest($request, $account);
        $pendingRequest = $connector->createPendingRequest($apiRequest);

        return new PreparedRateRequest(
            pendingRequest: $pendingRequest,
            carrierName: 'USPS',
        );
    }

    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
    {
        if (! $response->successful()) {
            Log::channel('usps-validation')->error('USPS API Error', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return collect();
        }

        $pricingOptions = $response->json('pricingOptions', []);

        if (empty($pricingOptions) || ! is_array($pricingOptions)) {
            Log::channel('usps-validation')->warning('USPS API returned empty or invalid pricingOptions', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return collect();
        }

        Log::channel('usps-validation')->debug('RATE RESPONSE', [
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

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

                $metadata = [
                    'mailClass' => $rate['mailClass'],
                    'processingCategory' => $rate['processingCategory'],
                    'rateIndicator' => $rate['rateIndicator'],
                    'destinationEntryFacilityType' => $rate['destinationEntryFacilityType'],
                ];

                $results->push(new RateResponse(
                    carrier: 'USPS',
                    serviceCode: $rate['mailClass'],
                    serviceName: $rate['description'] ?? $rate['mailClass'],
                    price: (float) ($rateOption['totalBasePrice'] ?? 0),
                    deliveryCommitment: $rateOption['commitment']['name'] ?? null,
                    deliveryDate: $rateOption['commitment']['scheduleDeliveryDate'] ?? null,
                    metadata: $metadata,
                    packagingRequirement: $this->classifyPackaging($metadata),
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
    private function buildRateApiRequest(RateRequest $request, ?CarrierAccount $account = null): ShippingOptions
    {
        $package = $request->packages[0];
        $isInternational = $request->destinationCountry !== 'US';

        $pricingType = $this->getPricingType($account);
        $pricingOption = ['priceType' => $pricingType];

        if ($pricingType === 'CONTRACT') {
            $pricingOption['paymentAccount'] = [
                'accountType' => 'EPS',
                'accountNumber' => $account?->credential('eps_account') ?? $account?->credential('crid'),
            ];
        }

        // Include mapped extra services so quoted prices carry their surcharges.
        // The rating endpoint does not enforce mail-class compatibility (it
        // false-positives on invalid combos) — carrier-service scope rows are
        // the real validation layer before purchase.
        $mapped = $this->mapExtraServices($request->specialServiceCodes, $request->specialServiceConfig, $isInternational);

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
                ...($mapped['extraServices'] !== [] ? ['extraServices' => $mapped['extraServices']] : []),
                ...($mapped['packageValue'] !== null ? ['packageValue' => $mapped['packageValue']] : []),
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

        Log::channel('usps-validation')->debug('RATE REQUEST', [
            'payload' => $body,
        ]);

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
        $connector = USPSConnector::getAuthenticatedConnector(
            $this->resolveAccount($package->location_id, $package->shipment?->client_id)
        );

        try {
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
                ->filter(fn ($detail): bool => is_array($detail))
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
                ->filter(fn ($event): bool => is_array($event))
                ->map(fn (array $event): TrackingEventData => $this->mapTrackingEvent($event))
                ->sortByDesc(fn (TrackingEventData $event) => $event->timestamp?->getTimestamp() ?? 0)
                ->values()
                ->all();

            $estimatedDeliveryAt = $this->parseUspsEstimatedDelivery($trackingDetail);
            $status = $this->mapTrackingStatus($trackingDetail, $events);
            $deliveredAt = $this->resolveDeliveredAt($events);

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
                'uri' => rtrim($connector->resolveBaseUrl(), '/').(new TrackShipment($package->tracking_number))->resolveEndpoint(),
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
            Log::channel('usps-validation')->error('USPS trackShipment error', [
                'tracking_number' => $package->tracking_number,
                'error' => $e->getMessage(),
            ]);

            return TrackShipmentResponse::failure('Unable to fetch USPS tracking information.');
        }
    }

    private function createDomesticShipment(ShipRequest $request): ShipResponse
    {
        try {
            $account = $this->resolveAccount($request->locationId, $request->clientId);
            $connector = USPSConnector::getAuthenticatedConnector($account);
            $paymentAuthorizationToken = USPSConnector::getUspsPaymentAuthorizationToken($account?->id);

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

            $mapped = $this->mapExtraServices($request->specialServiceCodes, $request->specialServiceConfig, isInternational: false);

            $body = [
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
                    'extraServices' => $mapped['extraServices'],
                    'destinationEntryFacilityType' => 'NONE',
                    ...$this->buildCustomerReference($request),
                    ...($mapped['packageOptions'] !== [] ? ['packageOptions' => $mapped['packageOptions']] : []),
                    ...($mapped['hazmat'] ? ['contentType' => 'HAZMAT'] : []),
                ],
                /**
                 * Mail to an overseas military or diplomatic post office crosses
                 * a customs boundary despite the domestic address, and USPS
                 * rejects the label without customs data. These ship at domestic
                 * prices on domestic mail classes, so the customs form is added
                 * here rather than rerouting to the international label API.
                 */
                ...($request->toAddress->isMilitary()
                    ? ['customsForm' => $this->buildCustomsForm($request)]
                    : []),
                'imageInfo' => $imageInfo,
            ];

            $apiRequest->body()->set($body);

            Log::channel('usps-validation')->debug('LABEL REQUEST', [
                'payload' => $body,
            ]);

            $response = $connector->send($apiRequest);

            if (! $response->successful()) {
                $payload = $this->decodeJsonSafely($response);
                $errorMessage = $this->describeLabelError($payload);
                Log::channel('usps-validation')->error('USPS createDomesticShipment API error', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                    'body' => $payload,
                ]);

                return ShipResponse::failure($errorMessage);
            }

            $response->parseBody();

            Log::channel('usps-validation')->debug('LABEL RESPONSE', [
                'metadata' => $response->metadata,
            ]);

            // Validate required response fields
            if (empty($response->metadata['trackingNumber'])) {
                Log::channel('usps-validation')->error('USPS createDomesticShipment missing tracking number', [
                    'metadata' => $response->metadata,
                ]);

                return ShipResponse::failure('USPS response missing tracking number');
            }

            if (empty($response->label)) {
                Log::channel('usps-validation')->error('USPS createDomesticShipment missing label data', [
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
                appliedServices: [
                    ...($request->hasSpecialService('saturday_delivery') ? ['saturday_delivery'] : []),
                    ...$mapped['appliedCodes'],
                ],
                carrierAccountId: $account?->id,
            );
        } catch (\Exception $e) {
            Log::channel('usps-validation')->error('USPS createDomesticShipment error', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ShipResponse::failure($this->describeLabelException($e));
        }
    }

    private function createInternationalShipment(ShipRequest $request): ShipResponse
    {
        try {
            $account = $this->resolveAccount($request->locationId, $request->clientId);
            $connector = USPSConnector::getAuthenticatedConnector($account);
            $paymentAuthorizationToken = USPSConnector::getUspsPaymentAuthorizationToken($account?->id);

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

            $mapped = $this->mapExtraServices($request->specialServiceCodes, $request->specialServiceConfig, isInternational: true);

            $body = [
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
                    'extraServices' => $mapped['extraServices'],
                    'destinationEntryFacilityType' => $metadata['destinationEntryFacilityType'] ?? 'INTERNATIONAL_SERVICE_CENTER',
                    ...$this->buildCustomerReference($request),
                    ...($mapped['packageOptions'] !== [] ? ['packageOptions' => $mapped['packageOptions']] : []),
                    ...($mapped['hazmat'] ? ['contentType' => 'HAZMAT'] : []),
                ],
                'customsForm' => $this->buildCustomsForm($request),
                'imageInfo' => $imageInfo,
            ];

            $apiRequest->body()->set($body);

            Log::channel('usps-validation')->debug('LABEL REQUEST', [
                'payload' => $body,
            ]);

            $response = $connector->send($apiRequest);

            if (! $response->successful()) {
                $payload = $this->decodeJsonSafely($response);
                $errorMessage = $this->describeLabelError($payload);
                Log::channel('usps-validation')->error('USPS createInternationalShipment API error', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                    'body' => $payload,
                ]);

                return ShipResponse::failure($errorMessage);
            }

            $response->parseBody();

            Log::channel('usps-validation')->debug('LABEL RESPONSE', [
                'metadata' => $response->metadata,
            ]);

            // International responses use 'internationalTrackingNumber' instead of 'trackingNumber'
            $trackingNumber = $response->metadata['internationalTrackingNumber']
                ?? $response->metadata['trackingNumber']
                ?? null;

            // Validate required response fields
            if (empty($trackingNumber)) {
                Log::channel('usps-validation')->error('USPS createInternationalShipment missing tracking number', [
                    'metadata' => $response->metadata,
                ]);

                return ShipResponse::failure('USPS response missing tracking number');
            }

            if (empty($response->label)) {
                Log::channel('usps-validation')->error('USPS createInternationalShipment missing label data', [
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
                appliedServices: [
                    ...($request->hasSpecialService('saturday_delivery') ? ['saturday_delivery'] : []),
                    ...$mapped['appliedCodes'],
                ],
                carrierAccountId: $account?->id,
            );
        } catch (\Exception $e) {
            Log::channel('usps-validation')->error('USPS createInternationalShipment error', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ShipResponse::failure($this->describeLabelException($e));
        }
    }

    /**
     * Turn a USPS label API error payload into something a packer can act on.
     *
     * @param  array<array-key, mixed>|null  $payload
     */
    private function describeLabelError(?array $payload, string $fallback = 'USPS rejected the label request.'): string
    {
        $described = [];

        foreach ($payload['error']['errors'] ?? [] as $error) {
            $code = (string) ($error['code'] ?? '');
            $detail = is_string($error['detail'] ?? null) ? trim($error['detail']) : null;

            $described[] = self::LABEL_ERROR_MESSAGES[$code] ?? $detail;
        }

        $described = array_unique(array_filter($described));

        if ($described !== []) {
            return implode(' ', $described);
        }

        $message = $payload['error']['message'] ?? $payload['message'] ?? null;

        // "Bad Request" restates the status code, and a schema validation dump
        // is longer than the panel can show. Neither helps at the pack bench.
        if (is_string($message)
            && trim($message) !== ''
            && ! in_array(strtolower(trim($message)), ['bad request', 'unauthorized', 'forbidden'], true)
            && ! str_contains($message, 'OASValidation')) {
            return mb_strimwidth(trim($message), 0, 300, '…');
        }

        return $fallback;
    }

    /**
     * Recover the USPS error payload from a thrown Saloon request exception.
     */
    private function describeLabelException(\Exception $exception): string
    {
        if (! $exception instanceof RequestException) {
            return $exception->getMessage();
        }

        // A gateway or WAF can answer with an HTML error page. This runs inside
        // a catch block, so decoding must not be able to throw again.
        return $this->describeLabelError($this->decodeJsonSafely($exception->getResponse()));
    }

    /**
     * Build the customs form for international and military shipments.
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

        // customerReference reaches USPS on a customs-bearing label but is only
        // written to the Shipping Services File — USPS does not print it
        // alongside a customs form. invoiceNumber is the field that shows up on
        // the form itself, so the reference is repeated there to keep an
        // international label matchable to its package.
        $reference = $this->labelReferences($request, maxLength: 30, maxCount: 1)[0] ?? null;

        return [
            'AESITN' => 'NO EEI 30.37(a)',
            'customsContentType' => 'MERCHANDISE',
            ...($reference !== null ? ['invoiceNumber' => $reference] : []),
            'contents' => $contents,
        ];
    }

    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
    {
        try {
            $account = $this->resolveAccount($package->location_id, $package->shipment->client_id);
            $connector = USPSConnector::getAuthenticatedConnector($account);
            $paymentAuthorizationToken = USPSConnector::getUspsPaymentAuthorizationToken($account?->id);
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

    public function supportsMultiPackage(): bool
    {
        return false;
    }

    public function supportsCarrierManifest(): bool
    {
        return true;
    }

    public function resolvePreSelectedRate(RateResponse $rate, Package $package): ?RateResponse
    {
        $packaging = PackageData::fromPackage($package)->carrierPackaging;
        $variants = $this->getRates(RateRequest::fromPackage($package), [$rate->serviceCode]);

        // No variant quoted: the rule's own rate stands, subject to the same
        // filter as everything else, rather than being returned unconditionally.
        if ($variants->isEmpty()) {
            $variants = collect([$rate]);
        }

        return PackagingFilter::keepCompatible($variants, $packaging)->sortBy('price')->first();
    }

    public function packagingRequirementFor(RateResponse $rate): PackagingRequirement
    {
        return $this->classifyPackaging($rate->metadata);
    }

    /**
     * Which packaging a USPS rate is valid in, read off the same `mailClass`
     * and `rateIndicator` the purchase sends — ADR-0005 decision 3.
     *
     * A flat-rate pair is `exactly(…)` the envelope or box USPS priced it for;
     * the single-piece and cubic indicators are the packer's own packaging.
     * Both inputs are needed because `FP` is the padded flat-rate envelope
     * under Priority Mail *and* Priority Mail Express, which are different
     * envelopes. The table is exhaustive over what {@see isValidRate()} keeps,
     * so a pair outside it is either browser-restated metadata or a USPS
     * product this code has never seen — and it is refused rather than
     * defaulted, since a default to `shipperPackaging()` would be a check the
     * browser could switch off.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws UnclassifiablePackagingException when the pair is not one USPS returns for a mail class this adapter sells
     */
    private function classifyPackaging(array $metadata): PackagingRequirement
    {
        $mailClass = (string) ($metadata['mailClass'] ?? '');
        $rateIndicator = (string) ($metadata['rateIndicator'] ?? '');

        $flatRate = self::FLAT_RATE_PACKAGING[$mailClass][$rateIndicator] ?? null;

        if ($flatRate instanceof CarrierPackaging) {
            return PackagingRequirement::exactly($flatRate);
        }

        if (in_array($rateIndicator, self::SHIPPER_PACKAGING_INDICATORS[$mailClass] ?? [], true)) {
            return PackagingRequirement::shipperPackaging();
        }

        throw new UnclassifiablePackagingException('USPS', "USPS rate indicator {$rateIndicator} under {$mailClass} is not one PolyBag can place in a packaging.");
    }

    /**
     * mailClass → the rate indicators USPS prices for the packer's own
     * packaging on that class. The other half of the classifier's table
     * beside {@see FLAT_RATE_PACKAGING}, and the mail-class allow-list
     * {@see isValidRate()} applies, so the filter and the classifier read one
     * table and cannot disagree.
     *
     * Read off every logged sandbox `search` response (packaging-form-and-
     * carrier-identity/05): Ground Advantage and Priority Mail price
     * single-piece and both cubic tables; Priority Mail Express, domestic and
     * international, prices single-piece under `PA` and has no cubic tier;
     * Parcel Select and the international parcel classes are single-piece
     * only. Media Mail and Library Mail are parcels too, but content-
     * restricted, and are deliberately absent; the presort classes never
     * carry a single-piece indicator. Global Express Guaranteed has never
     * appeared in a response and is absent until it does.
     *
     * @var array<string, list<string>>
     */
    private const SHIPPER_PACKAGING_INDICATORS = [
        'USPS_GROUND_ADVANTAGE' => self::DOMESTIC_PARCEL_INDICATORS,
        'PRIORITY_MAIL' => self::DOMESTIC_PARCEL_INDICATORS,
        'PRIORITY_MAIL_EXPRESS' => ['PA'],
        'PARCEL_SELECT' => ['SP'],
        'FIRST-CLASS_PACKAGE_INTERNATIONAL_SERVICE' => ['SP'],
        'PRIORITY_MAIL_INTERNATIONAL' => ['SP'],
        'PRIORITY_MAIL_EXPRESS_INTERNATIONAL' => ['PA'],
    ];

    /**
     * @var list<string>
     */
    private const DOMESTIC_PARCEL_INDICATORS = [
        'SP',
        ...self::BOX_RATE_INDICATORS,
        ...self::SOFT_PACK_RATE_INDICATORS,
    ];

    /**
     * (mailClass, rateIndicator) → the USPS packaging that rate is priced for.
     *
     * Read off the sandbox `search` response for a small box, recorded with
     * its source in packaging-form-and-carrier-identity/05. The international
     * classes return the same indicators for the same packaging, entered at
     * the ISC. Deliberately absent: `PM` (large flat rate box at the
     * APO/FPO/DPO price, returned for any destination and cheaper than `PL`)
     * and `E7` (the Express legal envelope at the holiday-delivery price) —
     * both are dropped by {@see isValidRateIndicator()} rather than offered as
     * a second price for the same box or envelope.
     *
     * @var array<string, array<string, CarrierPackaging>>
     */
    private const FLAT_RATE_PACKAGING = [
        'PRIORITY_MAIL' => self::PRIORITY_MAIL_FLAT_RATE_PACKAGING,
        'PRIORITY_MAIL_INTERNATIONAL' => self::PRIORITY_MAIL_FLAT_RATE_PACKAGING,
        'PRIORITY_MAIL_EXPRESS' => self::PRIORITY_MAIL_EXPRESS_FLAT_RATE_PACKAGING,
        'PRIORITY_MAIL_EXPRESS_INTERNATIONAL' => self::PRIORITY_MAIL_EXPRESS_FLAT_RATE_PACKAGING,
    ];

    /**
     * @var array<string, CarrierPackaging>
     */
    private const PRIORITY_MAIL_FLAT_RATE_PACKAGING = [
        'FE' => CarrierPackaging::UspsFlatRateEnvelope,
        'FA' => CarrierPackaging::UspsLegalFlatRateEnvelope,
        'FP' => CarrierPackaging::UspsPaddedFlatRateEnvelope,
        'FS' => CarrierPackaging::UspsSmallFlatRateBox,
        'FB' => CarrierPackaging::UspsMediumFlatRateBox,
        'PL' => CarrierPackaging::UspsLargeFlatRateBox,
    ];

    /**
     * @var array<string, CarrierPackaging>
     */
    private const PRIORITY_MAIL_EXPRESS_FLAT_RATE_PACKAGING = [
        'E4' => CarrierPackaging::UspsExpressFlatRateEnvelope,
        'E6' => CarrierPackaging::UspsExpressLegalFlatRateEnvelope,
        'FP' => CarrierPackaging::UspsExpressPaddedFlatRateEnvelope,
    ];

    /**
     * The flat-rate envelopes are priced as `FLATS`, the category
     * {@see isValidRate()} otherwise drops; these are the indicators it lets
     * through that filter. The boxes are `MACHINABLE` and need no exemption.
     */
    private const FLAT_RATE_ENVELOPE_INDICATORS = ['FE', 'FA', 'FP', 'E4', 'E6'];

    /**
     * Single-piece indicators, valid for all package types once their mail
     * class has admitted them: `SP`, and `PA` for Priority Mail Express.
     */
    private const UNIVERSAL_RATE_INDICATORS = ['SP', 'PA'];

    /**
     * @param  array<string, mixed>  $trackingDetail
     * @param  array<int, TrackingEventData>  $events
     */
    private function mapTrackingStatus(array $trackingDetail, array $events): TrackingStatus
    {
        // A stop-the-clock delivered event code (01/43/60) is authoritative and
        // terminal, so it takes precedence over the status text. This also keeps
        // this method in agreement with resolveDeliveredAt(): e.g. a code 43
        // "Picked Up" response carries no "DELIVERED" text but is still delivered.
        if (collect($events)->contains(fn (TrackingEventData $event): bool => $this->isDeliveredEvent($event))) {
            return TrackingStatus::Delivered;
        }

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
     * USPS PTR event codes that stop the delivery clock and count as delivered.
     * 01 = Delivered, 43 = Picked Up, 60 = Delivered to Agent for Final Delivery.
     * Deliberately excludes 59 (Out for Delivery) and 02/54-56 (Notice Left /
     * delivery attempt), whose descriptions also contain the substring "DELIVER".
     */
    private const DELIVERED_EVENT_CODES = ['01', '43', '60'];

    /**
     * USPS deliberately has no summary-status fallback: its predicted/expected
     * delivery dates are documented as 7-30% inaccurate and are suppressed after
     * end-of-day, so they are never trusted as an actual delivery timestamp. The
     * only source of truth is a delivered scan event (handled by the shared
     * resolveDeliveredAt template), so this returns null on purpose. Callers must
     * not overwrite an existing delivery date with that null.
     *
     * @param  array<string, mixed>  $summary
     */
    protected function deliveredAtFallback(array $summary): ?CarbonImmutable
    {
        return null;
    }

    protected function isDeliveredEvent(TrackingEventData $event): bool
    {
        $eventCode = strtoupper((string) $event->statusCode);

        if (in_array($eventCode, self::DELIVERED_EVENT_CODES, true)) {
            return true;
        }

        // Fallback for responses without a recognized event code: match an
        // explicit "DELIVERED" description but never "OUT FOR DELIVERY".
        $description = strtoupper($event->description);

        return str_contains($description, 'DELIVERED') && ! str_contains($description, 'OUT FOR');
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
        // Filter out non-applicable processing categories. The flat-rate
        // envelopes are priced as FLATS and are the one thing in that
        // category a parcel can ship in.
        if (in_array($rate['processingCategory'], ['CARDS', 'LETTERS', 'OPEN_AND_DISTRIBUTE'])) {
            return false;
        }

        if ($rate['processingCategory'] === 'FLATS' && ! in_array($rate['rateIndicator'], self::FLAT_RATE_ENVELOPE_INDICATORS, true)) {
            return false;
        }

        // Only the mail classes the classifier can place in a packaging —
        // which excludes Library Mail, Media Mail and the presort classes.
        if (! isset(self::SHIPPER_PACKAGING_INDICATORS[$rate['mailClass']])) {
            return false;
        }

        // Only include requested service codes (empty means all)
        if (! empty($serviceCodes) && ! in_array($rate['mailClass'], $serviceCodes)) {
            return false;
        }

        // Filter rate indicators based on box type
        if (! $this->isValidRateIndicator($rate['rateIndicator'], $rate['mailClass'], $boxType)) {
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
     *
     * This is the physical-form half of ADR-0005 decision 3: cubic tiers are
     * chosen by box type because both cubic tables are the packer's own
     * packaging and the shared filter cannot tell them apart. The flat-rate
     * indicators pass for every form — whether the Package is actually in
     * that envelope or box is the shared filter's question, answered from the
     * `exactly(…)` requirement {@see classifyPackaging()} stamps on the rate.
     */
    private function isValidRateIndicator(string $rateIndicator, string $mailClass, ?BoxSizeType $boxType): bool
    {
        if (isset(self::FLAT_RATE_PACKAGING[$mailClass][$rateIndicator])) {
            return true;
        }

        // The pair must be one USPS prices for the packer's own packaging on
        // this class — `PA` is Express-only, the cubic tiers are Ground
        // Advantage and Priority Mail only.
        if (! in_array($rateIndicator, self::SHIPPER_PACKAGING_INDICATORS[$mailClass] ?? [], true)) {
            return false;
        }

        // Universal rate indicators are always valid
        if (in_array($rateIndicator, self::UNIVERSAL_RATE_INDICATORS)) {
            return true;
        }

        // Packages with no box size (manual ship) have no box type to filter on
        if ($boxType === null) {
            return true;
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
