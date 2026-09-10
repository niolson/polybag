<?php

namespace App\Services\Carriers;

use App\Contracts\DirectCarrierAdapter;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\DataTransferObjects\Tracking\TrackingEventData;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\FedexPackageType;
use App\Enums\ServiceCapability;
use App\Enums\TrackingStatus;
use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\FedexRegistrationProxyConnector;
use App\Http\Integrations\Fedex\Requests\CancelShipment as CancelShipmentRequest;
use App\Http\Integrations\Fedex\Requests\CreateShipment;
use App\Http\Integrations\Fedex\Requests\Rates;
use App\Http\Integrations\Fedex\Requests\TrackShipment;
use App\Models\CarrierAccount;
use App\Models\Location;
use App\Models\Package;
use App\Services\Carriers\Concerns\BuildsCustomerReferences;
use App\Services\Carriers\Concerns\ConsultsCarrierPolicyForOffers;
use App\Services\Carriers\Concerns\HasDefaultServiceCapabilities;
use App\Services\Carriers\Concerns\HasSaturdayDelivery;
use App\Services\Carriers\Concerns\ResolvesCarrierAccount;
use App\Services\Carriers\Concerns\ResolvesDeliveredAt;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;

class FedexAdapter implements DirectCarrierAdapter
{
    use BuildsCustomerReferences;
    use ConsultsCarrierPolicyForOffers;
    use HasDefaultServiceCapabilities;
    use HasSaturdayDelivery;
    use ResolvesCarrierAccount;
    use ResolvesDeliveredAt;

    private function resolveConnector(?CarrierAccount $account): FedexConnector
    {
        return FedexConnector::getAuthenticatedConnector($account);
    }

    private function resolveAccountNumber(?CarrierAccount $account): ?string
    {
        return $account?->credential('account_number');
    }

    public function serviceCapability(string $serviceCode): ServiceCapability
    {
        return match ($serviceCode) {
            'saturday_delivery' => ServiceCapability::Supported,
            'signature_required' => ServiceCapability::Supported,
            'adult_signature_required' => ServiceCapability::Supported,
            'declared_value' => ServiceCapability::Supported,
            'alcohol' => ServiceCapability::Supported,
            'lithium_battery_in_equipment' => ServiceCapability::Supported,
            // Production availability probe (2026-07-09): STANDALONE_BATTERY is
            // not offered on any FedEx service for US lanes — standalone lithium
            // is full dangerous-goods territory, which is out of scope.
            'lithium_battery_standalone' => ServiceCapability::NotImplemented,
            'lithium_battery_ground_only' => ServiceCapability::Supported,
            // FedEx does not accept cremated remains (service guide prohibition)
            'cremated_remains' => ServiceCapability::Prohibited,
            default => ServiceCapability::NotImplemented,
        };
    }

    /**
     * FedEx accepts up to $50,000 declared value for customer packaging.
     */
    public function declaredValueCap(): ?float
    {
        return 50000.0;
    }

    /**
     * FedEx ground-network service codes. The BATTERY special service +
     * batteryDetails shape is an Express (air/IATA Section II) construct —
     * the production availability API surfaces ground batteries only as a
     * DANGEROUS_GOODS subtype. Ground shipments carry no battery API fields
     * (excepted batteries need package marks, not a declaration).
     */
    private const GROUND_NETWORK_SERVICES = ['FEDEX_GROUND', 'GROUND_HOME_DELIVERY', 'SMART_POST'];

    /**
     * Build the per-line-item packageSpecialServices and declaredValue fields
     * for the wired package-level special services (shared by the Rate and
     * Ship APIs, which use the same requestedPackageLineItems shape).
     *
     * @param  array<int, string>  $codes
     * @param  array<string, array<string, mixed>>  $config
     * @param  array<int, string>  $serviceCodes  FedEx service codes the request targets; battery fields are omitted when they are all ground-network
     * @return array{lineItemFields: array<string, mixed>, appliedCodes: array<int, string>}
     */
    private function buildPackageSpecialServices(array $codes, array $config, array $serviceCodes = []): array
    {
        $specialServiceTypes = [];
        $detail = [];
        $appliedCodes = [];
        $lineItemFields = [];

        if (in_array('adult_signature_required', $codes, true)) {
            $specialServiceTypes[] = 'SIGNATURE_OPTION';
            $detail['signatureOptionType'] = 'ADULT';
            $appliedCodes[] = 'adult_signature_required';
        } elseif (in_array('signature_required', $codes, true)) {
            $specialServiceTypes[] = 'SIGNATURE_OPTION';
            $detail['signatureOptionType'] = 'DIRECT';
            $appliedCodes[] = 'signature_required';
        }

        if (in_array('alcohol', $codes, true)) {
            $specialServiceTypes[] = 'ALCOHOL';
            // Direct-to-consumer is the common 3PL case; LICENSEE shipping
            // would need per-client config before it can be offered.
            $detail['alcoholDetail'] = ['alcoholRecipientType' => 'CONSUMER'];
            $appliedCodes[] = 'alcohol';
        }

        // Battery declaration is Express-only (IATA Section II); material is
        // assumed lithium-ion — the consumer-goods default, and the exact
        // combination the availability API enumerates (UN3481, PI967). Ground
        // requests carry no battery fields, and mixed rate requests omit them
        // too so a ground rate is never poisoned by an air-only construct
        // (express quotes then miss the small battery surcharge — accepted).
        $expressOnly = $serviceCodes !== []
            && array_intersect($serviceCodes, self::GROUND_NETWORK_SERVICES) === [];
        $batteryCode = in_array('lithium_battery_in_equipment', $codes, true)
            ? 'lithium_battery_in_equipment'
            : (in_array('lithium_battery_ground_only', $codes, true) ? 'lithium_battery_ground_only' : null);

        if ($batteryCode !== null && $expressOnly) {
            $specialServiceTypes[] = 'BATTERY';
            $detail['batteryDetails'] = [[
                'batteryPackingType' => 'CONTAINED_IN_EQUIPMENT',
                'batteryMaterialType' => 'LITHIUM_ION',
            ]];
            $appliedCodes[] = $batteryCode;
        }

        if ($specialServiceTypes !== []) {
            $lineItemFields['packageSpecialServices'] = [
                'specialServiceTypes' => $specialServiceTypes,
                ...$detail,
            ];
        }

        $declaredAmount = (float) ($config['declared_value']['amount'] ?? 0);

        if (in_array('declared_value', $codes, true) && $declaredAmount > 0) {
            $lineItemFields['declaredValue'] = [
                'amount' => round($declaredAmount, 2),
                'currency' => 'USD',
            ];
            $appliedCodes[] = 'declared_value';
        }

        return ['lineItemFields' => $lineItemFields, 'appliedCodes' => $appliedCodes];
    }

    /**
     * Service codes eligible for FedEx One Rate pricing.
     */
    private const ONE_RATE_ELIGIBLE_SERVICES = [
        'FIRST_OVERNIGHT',
        'PRIORITY_OVERNIGHT',
        'STANDARD_OVERNIGHT',
        'FEDEX_2_DAY_AM',
        'FEDEX_2_DAY',
        'EXPRESS_SAVER',
    ];

    /**
     * Map FedEx service codes to the day of week when Saturday delivery applies.
     * dayOfWeek values: 3=Wednesday, 4=Thursday, 5=Friday
     */
    private const SATURDAY_DELIVERY_DAY_MAP = [
        'FIRST_OVERNIGHT' => 5,      // Friday → Saturday (1-day)
        'PRIORITY_OVERNIGHT' => 5,   // Friday → Saturday (1-day)
        'STANDARD_OVERNIGHT' => 5,   // Friday → Saturday (1-day)
        'FEDEX_2_DAY_AM' => 4,       // Thursday → Saturday (2-day)
        'FEDEX_2_DAY' => 4,          // Thursday → Saturday (2-day)
        'EXPRESS_SAVER' => 3,        // Wednesday → Saturday (3-day)
    ];

    /**
     * @return array<int|string, int>
     */
    protected function saturdayDeliveryDayMap(): array
    {
        return self::SATURDAY_DELIVERY_DAY_MAP;
    }

    private const SMART_POST_SERVICE_CODE = 'SMART_POST';

    private const SMART_POST_LIGHTWEIGHT_INDICIA = 'PRESORTED_STANDARD';

    private const SMART_POST_HEAVYWEIGHT_INDICIA = 'PARCEL_SELECT';

    private const SMART_POST_LIGHTWEIGHT_ENDORSEMENT = 'ADDRESS_CORRECTION';

    public function getCarrierName(): string
    {
        return 'FedEx';
    }

    public function supportsTracking(): bool
    {
        return true;
    }

    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        if ($this->quotesInternationalLocally($request)) {
            return app(FedexSandboxInternationalRates::class)->ratesFor($request, $serviceCodes);
        }

        $prepared = $this->prepareRateRequest($request, $serviceCodes);

        if (! $prepared) {
            return collect();
        }

        $account = $this->resolveAccount($request->locationId, $request->clientId);
        $connector = $this->resolveConnector($account);
        $apiRequest = $this->buildRateApiRequest($this->adjustRequestForSaturday($request, $serviceCodes), $serviceCodes, $account);

        try {
            $response = $connector->send($apiRequest);
        } catch (RequestException $e) {
            // Saloon throws on 4xx/5xx when retries are exhausted. Pass the response
            // to parseRateResponse so the Saturday delivery retry logic can handle it.
            $response = $e->getResponse();
        }

        // Pass original $request so parseRateResponse knows Saturday was requested
        return $this->parseRateResponse($response, $request, $serviceCodes);
    }

    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
    {
        if (empty($request->packages)) {
            return null;
        }

        // Declining to prepare a request sends the caller back to getRates(),
        // which answers a sandbox international quote without an API call.
        if ($this->quotesInternationalLocally($request)) {
            return null;
        }

        $account = $this->resolveAccount($request->locationId, $request->clientId);
        $connector = $this->resolveConnector($account);
        $apiRequest = $this->buildRateApiRequest($this->adjustRequestForSaturday($request, $serviceCodes), $serviceCodes, $account);
        $pendingRequest = $connector->createPendingRequest($apiRequest);

        return new PreparedRateRequest(
            pendingRequest: $pendingRequest,
            carrierName: 'FedEx',
        );
    }

    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
    {
        $account = $this->resolveAccount($request->locationId, $request->clientId);

        if (! $response->successful()) {
            // If Saturday delivery was requested, retry without it
            if ($request->hasSpecialService('saturday_delivery')) {
                $errors = $response->json('errors', []);
                $isSaturdayError = collect($errors)->contains(
                    fn ($e): bool => ($e['code'] ?? '') === 'SERVICE.PACKAGECOMBINATION.INVALID'
                );

                if ($isSaturdayError) {
                    logger()->info('FedEx Saturday delivery not available for this destination, retrying without');
                    $requestWithout = $this->withoutSaturdayDelivery($request);
                    $connector = $this->resolveConnector($account);
                    $apiRequest = $this->buildRateApiRequest($requestWithout, $serviceCodes, $account);
                    $retryResponse = $connector->send($apiRequest);

                    return $this->parseRateResponse($retryResponse, $requestWithout, $serviceCodes);
                }
            }

            $errors = $response->json('errors', []);
            Log::channel('fedex-validation')->error('FedEx API Error', [
                'status' => $response->status(),
                'errors' => $errors,
                'body' => $response->json(),
            ]);

            return collect();
        }

        Log::channel('fedex-validation')->debug('RATE RESPONSE', [
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        $results = $this->extractRateDetails($response, $serviceCodes);

        // Mixed Saturday: initial request was sent without Saturday, now send
        // a follow-up with Saturday for eligible services and merge results
        if ($request->hasSpecialService('saturday_delivery') && $this->classifySaturdayEligibility($serviceCodes, $request) === 'mixed') {
            try {
                $connector = $this->resolveConnector($account);
                $saturdayApiRequest = $this->buildRateApiRequest($request, $serviceCodes, $account);
                $saturdayResponse = $connector->send($saturdayApiRequest);

                if ($saturdayResponse->successful()) {
                    $saturdayRates = $this->extractRateDetails($saturdayResponse, $serviceCodes);

                    if ($saturdayRates->isNotEmpty()) {
                        $saturdayServiceCodes = $saturdayRates->pluck('serviceCode')->unique()->all();
                        $results = $results->reject(
                            fn ($rate): bool => in_array($rate->serviceCode, $saturdayServiceCodes)
                                && empty($rate->metadata['isOneRate'])
                        );
                        $results = $results->merge($saturdayRates);
                    }
                } else {
                    Log::channel('fedex-validation')->warning('FedEx Saturday delivery rate request failed', [
                        'status' => $saturdayResponse->status(),
                        'errors' => $saturdayResponse->json('errors', []),
                    ]);
                }
            } catch (\Exception $e) {
                logger()->warning('FedEx Saturday delivery rate request error', ['error' => $e->getMessage()]);
            }
        }

        // Fetch One Rate prices if eligible and merge them in
        if ($this->isOneRateEligible($request)) {
            $oneRateResults = $this->fetchOneRateRates($request, $serviceCodes, $account);
            $results = $results->merge($oneRateResults);
        }

        return $results;
    }

    /**
     * Build the FedEx rate API request.
     */
    private function buildRateApiRequest(RateRequest $request, array $serviceCodes, ?CarrierAccount $account): Rates
    {
        if ($this->isSandbox()) {
            // The FedEx sandbox returns truncated (unparseable) JSON for most request
            // shapes. The example payload from the FedEx developer docs is the one known
            // request that produces a valid, complete response from the sandbox API.
            $apiRequest = new Rates;
            $apiRequest->body()->set([
                'accountNumber' => ['value' => '740561073'],
                'rateRequestControlParameters' => [
                    'returnTransitTimes' => true,
                ],
                'requestedShipment' => [
                    'shipper' => ['address' => ['postalCode' => '65247', 'countryCode' => 'US']],
                    'recipient' => ['address' => ['postalCode' => '72348', 'countryCode' => 'US']],
                    'pickupType' => 'DROPOFF_AT_FEDEX_LOCATION',
                    'rateRequestType' => ['ACCOUNT', 'LIST'],
                    'requestedPackageLineItems' => [
                        ['weight' => ['units' => 'LB', 'value' => '1']],
                    ],
                ],
            ]);

            return $apiRequest;
        }

        $package = $request->packages[0];
        $smartPostInfoDetail = $this->buildSmartPostInfoDetail($request, $serviceCodes);
        $lineItemFields = $this->buildPackageSpecialServices(
            $request->specialServiceCodes,
            $request->specialServiceConfig,
            $serviceCodes,
        )['lineItemFields'];

        $apiRequest = new Rates;

        $apiRequest->body()->set([
            'accountNumber' => [
                'value' => $this->resolveAccountNumber($account),
            ],
            'rateRequestControlParameters' => [
                'returnTransitTimes' => true,
            ],
            'requestedShipment' => [
                'shipper' => [
                    'address' => [
                        'postalCode' => $request->originPostalCode,
                        'countryCode' => $request->originCountry,
                    ],
                ],
                'recipient' => [
                    'address' => [
                        'postalCode' => $request->destinationPostalCode,
                        'countryCode' => $request->destinationCountry,
                    ],
                ],
                'pickupType' => 'USE_SCHEDULED_PICKUP',
                'rateRequestType' => ['ACCOUNT'],
                ...($smartPostInfoDetail ? [
                    'serviceType' => self::SMART_POST_SERVICE_CODE,
                ] : []),
                'requestedPackageLineItems' => [
                    [
                        'weight' => [
                            'units' => 'LB',
                            'value' => $package->weight,
                        ],
                        ...$lineItemFields,
                    ],
                ],
                ...($smartPostInfoDetail ? [
                    'smartPostInfoDetail' => $smartPostInfoDetail,
                ] : []),
                ...($request->shipDate ? [
                    'shipDateStamp' => $request->shipDate->format('Y-m-d'),
                ] : []),
                ...($request->hasSpecialService('saturday_delivery') ? [
                    'shipmentSpecialServices' => [
                        'specialServiceTypes' => ['SATURDAY_DELIVERY'],
                    ],
                ] : []),
                ...($this->isInternational($request) ? [
                    'customsClearanceDetail' => [
                        'dutiesPayment' => [
                            'paymentType' => 'SENDER',
                        ],
                        'commodities' => [
                            [
                                'description' => 'Merchandise',
                                'quantity' => 1,
                                'quantityUnits' => 'PCS',
                                'weight' => [
                                    'units' => 'LB',
                                    'value' => $package->weight,
                                ],
                                'customsValue' => [
                                    'amount' => '1.00',
                                    'currency' => 'USD',
                                ],
                            ],
                        ],
                    ],
                ] : []),
            ],
        ]);

        Log::channel('fedex-validation')->info('RATE REQUEST', ['payload' => $apiRequest->body()->all()]);

        return $apiRequest;
    }

    /**
     * @param  array<int, string>  $serviceCodes
     * @return array<string, string>|null
     */
    private function buildSmartPostInfoDetail(RateRequest $request, array $serviceCodes): ?array
    {
        if (! in_array(self::SMART_POST_SERVICE_CODE, $serviceCodes, true)) {
            return null;
        }

        $hubId = $this->resolveFedexHubId($request->locationId);
        if (! filled($hubId)) {
            logger()->warning('FedEx SmartPost requested but no hub ID is configured for the origin location', [
                'location_id' => $request->locationId,
            ]);

            return null;
        }

        $weight = (float) ($request->packages[0]->weight ?? 0);
        if ($weight < 1.0) {
            return [
                'hubId' => $hubId,
                'indicia' => self::SMART_POST_LIGHTWEIGHT_INDICIA,
                'ancillaryEndorsement' => self::SMART_POST_LIGHTWEIGHT_ENDORSEMENT,
            ];
        }

        return [
            'hubId' => $hubId,
            'indicia' => self::SMART_POST_HEAVYWEIGHT_INDICIA,
        ];
    }

    private function resolveFedexHubId(?int $locationId): ?string
    {
        $location = $locationId
            ? Location::query()->find($locationId)
            : Location::getDefault();

        return filled($location?->fedex_hub_id) ? (string) $location->fedex_hub_id : null;
    }

    /**
     * @return array<string, string>|null
     */
    private function buildShipmentSmartPostInfoDetail(ShipRequest $request): ?array
    {
        $hubId = $this->resolveFedexHubId($request->locationId);
        if (! filled($hubId)) {
            logger()->warning('FedEx SmartPost shipment requested but no hub ID is configured for the origin location', [
                'location_id' => $request->locationId,
            ]);

            return null;
        }

        $weight = (float) $request->packageData->weight;
        if ($weight < 1.0) {
            return [
                'hubId' => $hubId,
                'indicia' => self::SMART_POST_LIGHTWEIGHT_INDICIA,
                'ancillaryEndorsement' => self::SMART_POST_LIGHTWEIGHT_ENDORSEMENT,
            ];
        }

        return [
            'hubId' => $hubId,
            'indicia' => self::SMART_POST_HEAVYWEIGHT_INDICIA,
        ];
    }

    public function createShipment(ShipRequest $request): ShipResponse
    {
        $account = $this->resolveAccount($request->locationId, $request->clientId);

        try {
            $connector = $this->resolveConnector($account);

            $packageLevelServices = $this->buildPackageSpecialServices(
                $request->specialServiceCodes,
                $request->specialServiceConfig,
                [$request->selectedRate->metadata['serviceType'] ?? $request->selectedRate->serviceCode],
            );

            $requestedShipment = [
                'shipper' => $this->buildContact($request->fromAddress),
                'recipients' => [
                    // Standing in the shipper's phone keeps FedEx from rejecting a
                    // domestic shipment outright over a missing recipient number.
                    // Not done once customs is involved: the recipient contact then
                    // feeds a customs declaration, and naming the warehouse there
                    // would misstate who receives the goods.
                    $this->buildContact(
                        $request->toAddress,
                        $request->toAddress->requiresCustomsDeclaration() ? null : $request->fromAddress->phone,
                    ),
                ],
                ...($request->shipDate ? [
                    'shipDateStamp' => $request->shipDate->format('Y-m-d'),
                ] : []),
                'pickupType' => 'USE_SCHEDULED_PICKUP',
                'serviceType' => $request->selectedRate->metadata['serviceType'],
                'packagingType' => ! empty($request->selectedRate->metadata['isOneRate'])
                    ? $request->selectedRate->metadata['fedexPackageType']
                    : 'YOUR_PACKAGING',
                'shippingChargesPayment' => [
                    'paymentType' => 'SENDER',
                    'payor' => [
                        'responsibleParty' => [
                            'accountNumber' => [
                                'value' => $this->resolveAccountNumber($account),
                            ],
                        ],
                    ],
                ],
                'labelSpecification' => array_filter([
                    'labelFormatType' => 'COMMON2D',
                    'imageType' => $request->labelFormat === 'zpl' ? 'ZPLII' : 'PDF',
                    'labelStockType' => 'STOCK_4X6',
                    'resolution' => $request->labelFormat === 'zpl' ? ($request->labelDpi === 300 ? 300 : 200) : null,
                ], fn ($v): bool => $v !== null),
                'requestedPackageLineItems' => [
                    [
                        'weight' => [
                            'units' => 'LB',
                            'value' => $request->packageData->weight,
                        ],
                        'dimensions' => [
                            'length' => (int) $request->packageData->length,
                            'width' => (int) $request->packageData->width,
                            'height' => (int) $request->packageData->height,
                            'units' => 'IN',
                        ],
                        ...$this->buildCustomerReferences($request),
                        ...$packageLevelServices['lineItemFields'],
                    ],
                ],
            ];

            if (($request->selectedRate->metadata['serviceType'] ?? null) === self::SMART_POST_SERVICE_CODE) {
                $smartPostInfoDetail = $this->buildShipmentSmartPostInfoDetail($request);

                if ($smartPostInfoDetail) {
                    $requestedShipment['smartPostInfoDetail'] = $smartPostInfoDetail;
                }
            }

            // Comparing the two country codes misses destinations that cross a
            // customs boundary while still reading as US — Puerto Rico and the
            // other territories come through as country US, and FedEx rejects
            // those labels with TOTALCUSTOMSVALUE.REQUIRED. Ask the addresses
            // whether they share a customs area rather than deciding it here.
            if (! $request->toAddress->sharesCustomsZoneWith($request->fromAddress)) {
                if (empty($request->customsItems)) {
                    return ShipResponse::failure(
                        'FedEx requires a customs declaration for this destination, but the package has no items to declare.'
                    );
                }

                $requestedShipment['customsClearanceDetail'] = $this->buildCustomsClearanceDetail($request, $account);
            }

            // Build special service types
            $specialServiceTypes = [];
            if (! empty($request->selectedRate->metadata['isOneRate'])) {
                $specialServiceTypes[] = 'FEDEX_ONE_RATE';
            }
            $saturdayRequested = $request->hasSpecialService('saturday_delivery');
            if ($saturdayRequested) {
                $specialServiceTypes[] = 'SATURDAY_DELIVERY';
            }
            if (! empty($specialServiceTypes)) {
                $requestedShipment['shipmentSpecialServices'] = [
                    'specialServiceTypes' => $specialServiceTypes,
                ];
            }

            Log::channel('fedex-validation')->debug('LABEL REQUEST', [
                'payload' => $requestedShipment,
            ]);

            $response = $this->sendCreateShipment($connector, $requestedShipment, $account);
            $responseData = $response->json();

            // If Saturday delivery was rejected, retry without it
            $saturdayApplied = $saturdayRequested;
            if ($saturdayRequested && ! $response->successful()) {
                $errors = $responseData['errors'] ?? [];
                $isSaturdayError = collect($errors)->contains(function ($e): bool {
                    $code = $e['code'] ?? '';
                    $message = strtolower($e['message'] ?? '');
                    // Check message or code for Saturday references
                    if (str_contains($message, 'saturday') || str_contains(strtolower($code), 'saturday')) {
                        return true;
                    }
                    // Errors that reference SATURDAY_DELIVERY in parameterList
                    $saturdayInParams = collect($e['parameterList'] ?? [])->contains(
                        fn ($p): bool => ($p['value'] ?? '') === 'SATURDAY_DELIVERY'
                    );
                    if ($saturdayInParams) {
                        return true;
                    }
                    // Generic special-service rejection codes when Saturday was the only service requested
                    if (in_array($code, ['SHIPMENT.SPECIALSERVICETYPE.NOTALLOWED', 'ORGORDEST.SPECIALSERVICES.NOTALLOWED'])) {
                        return true;
                    }

                    return false;
                });

                if ($isSaturdayError) {
                    Log::channel('fedex-validation')->info('FedEx Saturday delivery rejected, retrying without', [
                        'errors' => $errors,
                    ]);
                    $saturdayApplied = false;
                    // Remove only SATURDAY_DELIVERY, preserve other special services (e.g. FEDEX_ONE_RATE)
                    $existingTypes = $requestedShipment['shipmentSpecialServices']['specialServiceTypes'] ?? [];
                    $remainingTypes = array_values(array_filter($existingTypes, fn ($t): bool => $t !== 'SATURDAY_DELIVERY'));
                    if (empty($remainingTypes)) {
                        unset($requestedShipment['shipmentSpecialServices']);
                    } else {
                        $requestedShipment['shipmentSpecialServices']['specialServiceTypes'] = $remainingTypes;
                    }
                    $response = $this->sendCreateShipment($connector, $requestedShipment, $account);
                    $responseData = $response->json();
                }
            }

            // Build the list of our service codes that were actually applied
            $appliedServices = $packageLevelServices['appliedCodes'];
            if ($saturdayApplied) {
                $appliedServices[] = 'saturday_delivery';
            }

            if (! $response->successful()) {
                $errors = $responseData['errors'] ?? [];
                $errorMessage = ! empty($errors) ? ($errors[0]['message'] ?? 'Unknown FedEx error') : 'FedEx API error';
                Log::channel('fedex-validation')->error('FedEx createShipment API error', [
                    'status' => $response->status(),
                    'errors' => $errors,
                    'body' => $responseData,
                ]);

                return ShipResponse::failure($errorMessage);
            }

            Log::channel('fedex-validation')->debug('LABEL RESPONSE', [
                'body' => $responseData,
            ]);

            $shipmentData = $responseData['output']['transactionShipments'][0] ?? null;

            if (! $shipmentData) {
                Log::channel('fedex-validation')->error('FedEx createShipment missing shipment data', [
                    'output' => $responseData['output'] ?? null,
                ]);

                return ShipResponse::failure('FedEx response missing shipment data');
            }

            $trackingNumber = $shipmentData['masterTrackingNumber']
                ?? $shipmentData['pieceResponses'][0]['trackingNumber']
                ?? null;

            if (empty($trackingNumber)) {
                Log::channel('fedex-validation')->error('FedEx createShipment missing tracking number', [
                    'shipmentData' => $shipmentData,
                ]);

                return ShipResponse::failure('FedEx response missing tracking number');
            }

            $labelData = $shipmentData['pieceResponses'][0]['packageDocuments'][0]['encodedLabel'] ?? null;

            if (empty($labelData)) {
                Log::channel('fedex-validation')->error('FedEx createShipment missing label data', [
                    'pieceResponses' => $shipmentData['pieceResponses'] ?? null,
                ]);

                return ShipResponse::failure('FedEx response missing label data');
            }

            $totalCharge = $shipmentData['completedShipmentDetail']['shipmentRating']['shipmentRateDetails'][0]['totalNetCharge']
                ?? $request->selectedRate->price;

            return ShipResponse::success(
                trackingNumber: $trackingNumber,
                cost: (float) $totalCharge,
                carrier: 'FedEx',
                service: $request->selectedRate->serviceName,
                labelData: $labelData,
                labelFormat: $request->labelFormat,
                labelDpi: $request->labelDpi,
                shipDate: $request->shipDate,
                appliedServices: $appliedServices,
                carrierAccountId: $account?->id,
            );
        } catch (\Exception $e) {
            Log::channel('fedex-validation')->error('FedEx createShipment error', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ShipResponse::failure($e->getMessage());
        }
    }

    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
    {
        $account = $this->resolveAccount($package->location_id, $package->shipment?->client_id);

        try {
            $connector = $this->resolveConnector($account);

            $apiRequest = new CancelShipmentRequest;
            $apiRequest->body()->set([
                'accountNumber' => [
                    'value' => $this->resolveAccountNumber($account),
                ],
                'trackingNumber' => $trackingNumber,
            ]);

            $response = $connector->send($apiRequest);

            if ($response->successful()) {
                return CancelResponse::success('FedEx shipment cancelled.');
            }

            return CancelResponse::failure('FedEx returned status '.$response->status());
        } catch (\Exception $e) {
            logger()->error('FedEx cancelShipment error', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'tracking_number' => $trackingNumber,
            ]);

            return CancelResponse::failure($e->getMessage());
        }
    }

    public function trackShipment(Package $package): TrackShipmentResponse
    {
        try {
            if (config('services.oauth.broker_url')) {
                $connector = new FedexRegistrationProxyConnector;
            } else {
                $connector = $this->resolveConnector(
                    $this->resolveAccount($package->location_id, $package->shipment?->client_id)
                );
            }

            $trackRequest = new TrackShipment($package->tracking_number);
            $response = $connector->send($trackRequest);

            Log::channel('fedex-validation')->info('TRACK REQUEST', ['tracking_number' => $package->tracking_number]);
            Log::channel('fedex-validation')->info('TRACK RESPONSE', ['status' => $response->status(), 'body' => $response->json()]);

            if (! $response->successful()) {
                return TrackShipmentResponse::failure(
                    collect($response->json('errors', []))->pluck('message')->filter()->join(' ')
                    ?: 'FedEx tracking request failed.',
                    ['raw' => $response->json()],
                );
            }

            $trackResult = $response->json('output.completeTrackResults.0.trackResults.0');

            if (! is_array($trackResult)) {
                return TrackShipmentResponse::failure('FedEx returned an unexpected tracking response.', [
                    'raw' => $response->json(),
                ]);
            }

            $statusCode = (string) data_get($trackResult, 'latestStatusDetail.code', '');
            $statusLabel = data_get($trackResult, 'latestStatusDetail.description')
                ?? data_get($trackResult, 'latestStatusDetail.statusByLocale')
                ?? $statusCode;

            $events = collect(data_get($trackResult, 'scanEvents', []))
                ->filter(fn ($event): bool => is_array($event))
                ->map(fn (array $event): TrackingEventData => $this->mapTrackingEvent($event))
                ->sortByDesc(fn (TrackingEventData $event) => $event->timestamp?->getTimestamp() ?? 0)
                ->values()
                ->all();

            $estimatedDeliveryAt = $this->parseFedexDate(
                data_get($trackResult, 'estimatedDeliveryTimeWindow.window.ends')
                ?? data_get($trackResult, 'dateAndTimes.0.dateTime')
                ?? data_get($trackResult, 'estimatedDeliveryTimestamp')
            );

            $deliveredAt = $this->resolveDeliveredAt($events, $trackResult);
            $status = $this->mapTrackingStatus($statusCode, (string) $statusLabel);

            return TrackShipmentResponse::success(
                status: $status,
                events: $events,
                estimatedDeliveryAt: $estimatedDeliveryAt,
                deliveredAt: $deliveredAt,
                statusLabel: $statusLabel,
                details: [
                    'raw' => $response->json(),
                ],
            );
        } catch (\Throwable $e) {
            Log::channel('fedex-validation')->error('FedEx trackShipment error', [
                'tracking_number' => $package->tracking_number,
                'error' => $e->getMessage(),
            ]);

            return TrackShipmentResponse::failure('Unable to fetch FedEx tracking information.');
        }
    }

    private function mapTrackingStatus(string $statusCode, string $statusLabel): TrackingStatus
    {
        $normalizedCode = strtoupper($statusCode);
        $normalizedLabel = strtoupper($statusLabel);

        return match (true) {
            str_contains($normalizedCode, 'DL') || str_contains($normalizedLabel, 'DELIVER') => TrackingStatus::Delivered,
            str_contains($normalizedCode, 'OD') || str_contains($normalizedLabel, 'OUT FOR DELIVERY') => TrackingStatus::OutForDelivery,
            str_contains($normalizedCode, 'RS') || str_contains($normalizedLabel, 'RETURN') => TrackingStatus::Returned,
            str_contains($normalizedCode, 'HL')
                || str_contains($normalizedLabel, 'READY FOR PICKUP')
                || str_contains($normalizedLabel, 'PICKUP')
                || str_contains($normalizedLabel, 'HOLD') => TrackingStatus::Exception,
            str_contains($normalizedCode, 'DE') || str_contains($normalizedCode, 'SE')
                || str_contains($normalizedLabel, 'EXCEPTION')
                || str_contains($normalizedLabel, 'DELAY') => TrackingStatus::Exception,
            str_contains($normalizedCode, 'IT') || str_contains($normalizedCode, 'AR')
                || str_contains($normalizedLabel, 'TRANSIT') => TrackingStatus::InTransit,
            default => TrackingStatus::PreTransit,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function mapTrackingEvent(array $event): TrackingEventData
    {
        $locationParts = array_filter([
            data_get($event, 'scanLocation.city'),
            data_get($event, 'scanLocation.stateOrProvinceCode'),
            data_get($event, 'scanLocation.countryCode'),
        ]);

        return new TrackingEventData(
            timestamp: $this->parseFedexDate((string) ($event['date'] ?? '')),
            location: empty($locationParts) ? null : implode(', ', $locationParts),
            description: (string) (data_get($event, 'eventDescription') ?: data_get($event, 'exceptionDescription') ?: 'Tracking update'),
            statusCode: data_get($event, 'derivedStatusCode'),
            status: data_get($event, 'derivedStatus'),
            raw: $event,
        );
    }

    protected function isDeliveredEvent(TrackingEventData $event): bool
    {
        return $event->statusCode === 'DL';
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function deliveredAtFallback(array $summary): ?CarbonImmutable
    {
        $statusCode = (string) data_get($summary, 'latestStatusDetail.code', '');

        if ($this->mapTrackingStatus($statusCode, (string) data_get($summary, 'latestStatusDetail.description', '')) !== TrackingStatus::Delivered) {
            return null;
        }

        return $this->parseFedexDate(
            data_get($summary, 'dateAndTimes.0.dateTime') ?? data_get($summary, 'actualDeliveryTimestamp')
        );
    }

    private function parseFedexDate(?string $value): ?CarbonImmutable
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function supportsMultiPackage(): bool
    {
        return true;
    }

    public function supportsCarrierManifest(): bool
    {
        return false;
    }

    public function resolvePreSelectedRate(RateResponse $rate, Package $package): RateResponse
    {
        return $rate;
    }

    /**
     * Build FedEx contact/address structure from AddressData DTO.
     *
     * @return array<string, mixed>
     */
    /**
     * Extract rate details from a successful FedEx rate response.
     * Core parsing loop used by parseRateResponse and mixed Saturday handling.
     */
    private function extractRateDetails(Response $response, array $serviceCodes): Collection
    {
        try {
            $rateReplyDetails = $response->json('output.rateReplyDetails', []);
        } catch (\JsonException $e) {
            // FedEx sandbox returns truncated JSON (confirmed: Postman also receives the
            // same cut-off response). Nothing to fix here; just return empty rates.
            logger()->warning('FedEx rate response could not be decoded — likely truncated sandbox response', [
                'error' => $e->getMessage(),
                'body_len' => strlen($response->body()),
            ]);

            return collect();
        }

        if (! is_array($rateReplyDetails)) {
            Log::channel('fedex-validation')->warning('FedEx API returned invalid rateReplyDetails', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return collect();
        }

        $returnedServiceTypes = array_map(fn ($d): mixed => $d['serviceType'] ?? 'unknown', $rateReplyDetails);
        logger()->debug('FedEx rate response filtering', [
            'returned_services' => $returnedServiceTypes,
            'requested_codes' => $serviceCodes,
        ]);

        $results = collect();

        foreach ($rateReplyDetails as $detail) {
            if (! empty($serviceCodes) && ! in_array($detail['serviceType'] ?? '', $serviceCodes)) {
                continue;
            }

            $ratedShipmentDetails = $detail['ratedShipmentDetails'][0] ?? null;

            if (! $ratedShipmentDetails) {
                continue;
            }

            $transitDays = $detail['commit']['transitDays'] ?? null;
            $transitTime = is_string($transitDays) ? $transitDays : ($transitDays['minimumTransitTime'] ?? null);
            $deliveryDate = $detail['commit']['dateDetail']['dayFormat'] ?? $detail['commit']['dateDetail']['dayOfWeek'] ?? null;

            $results->push(new RateResponse(
                carrier: 'FedEx',
                serviceCode: $detail['serviceType'],
                serviceName: $detail['serviceName'] ?? $detail['serviceType'],
                price: (float) ($ratedShipmentDetails['totalNetCharge'] ?? 0),
                deliveryDate: $deliveryDate,
                transitTime: $transitTime,
                metadata: [
                    'serviceType' => $detail['serviceType'],
                ],
            ));
        }

        return $results;
    }

    /**
     * Classify Saturday delivery eligibility for the requested service codes.
     * Returns 'all', 'none', or 'mixed' based on today's day of week.
     */
    /**
     * Check if the request is eligible for FedEx One Rate pricing.
     * Requires: FedEx-branded packaging, domestic US, weight ≤ 50 lbs.
     */
    private function isOneRateEligible(RateRequest $request): bool
    {
        $package = $request->packages[0] ?? null;

        if (! $package) {
            return false;
        }

        $fedexType = $package->fedexPackageType;

        if (! $fedexType || $fedexType === FedexPackageType::YOUR_PACKAGING) {
            return false;
        }

        if ($request->destinationCountry !== 'US' || $request->originCountry !== 'US') {
            return false;
        }

        if ($package->weight > 50) {
            return false;
        }

        return true;
    }

    /**
     * Fetch One Rate rates from FedEx API.
     * Returns empty collection on failure (non-fatal).
     */
    private function fetchOneRateRates(RateRequest $request, array $serviceCodes, ?CarrierAccount $account): Collection
    {
        try {
            $connector = $this->resolveConnector($account);
            $apiRequest = $this->buildOneRateApiRequest($request, $account);
            $response = $connector->send($apiRequest);

            if (! $response->successful()) {
                Log::channel('fedex-validation')->warning('FedEx One Rate request failed', [
                    'status' => $response->status(),
                    'errors' => $response->json('errors', []),
                ]);

                return collect();
            }

            Log::channel('fedex-validation')->debug('RATE RESPONSE (One Rate)', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return $this->parseOneRateResponse($response, $request, $serviceCodes);
        } catch (\Exception $e) {
            logger()->warning('FedEx One Rate request error', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * Build the FedEx One Rate API request.
     */
    private function buildOneRateApiRequest(RateRequest $request, ?CarrierAccount $account): Rates
    {
        $package = $request->packages[0];

        $apiRequest = new Rates;
        $apiRequest->body()->set([
            'accountNumber' => [
                'value' => $this->resolveAccountNumber($account),
            ],
            'rateRequestControlParameters' => [
                'returnTransitTimes' => true,
            ],
            'requestedShipment' => [
                'shipper' => [
                    'address' => [
                        'postalCode' => $request->originPostalCode,
                        'countryCode' => $request->originCountry,
                    ],
                ],
                'recipient' => [
                    'address' => [
                        'postalCode' => $request->destinationPostalCode,
                        'countryCode' => $request->destinationCountry,
                    ],
                ],
                'pickupType' => 'USE_SCHEDULED_PICKUP',
                'rateRequestType' => ['ACCOUNT'],
                ...($request->shipDate ? [
                    'shipDateStamp' => $request->shipDate->format('Y-m-d'),
                ] : []),
                'packagingType' => $package->fedexPackageType->value,
                'requestedPackageLineItems' => [
                    [
                        'weight' => [
                            'units' => 'LB',
                            'value' => $package->weight,
                        ],
                    ],
                ],
                'shipmentSpecialServices' => [
                    'specialServiceTypes' => array_filter([
                        'FEDEX_ONE_RATE',
                        $request->hasSpecialService('saturday_delivery') ? 'SATURDAY_DELIVERY' : null,
                    ]),
                ],
            ],
        ]);

        Log::channel('fedex-validation')->debug('RATE REQUEST (One Rate)', [
            'payload' => $apiRequest->body()->all(),
        ]);

        return $apiRequest;
    }

    /**
     * Parse One Rate response, appending " (One Rate)" to service names.
     */
    private function parseOneRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
    {
        $rateReplyDetails = $response->json('output.rateReplyDetails', []);

        if (! is_array($rateReplyDetails)) {
            return collect();
        }

        $package = $request->packages[0];
        $results = collect();

        foreach ($rateReplyDetails as $detail) {
            $serviceType = $detail['serviceType'] ?? '';

            if (! empty($serviceCodes) && ! in_array($serviceType, $serviceCodes)) {
                continue;
            }

            // Only include One Rate eligible services
            if (! in_array($serviceType, self::ONE_RATE_ELIGIBLE_SERVICES)) {
                continue;
            }

            $ratedShipmentDetails = $detail['ratedShipmentDetails'][0] ?? null;

            if (! $ratedShipmentDetails) {
                continue;
            }

            $transitDays = $detail['commit']['transitDays'] ?? null;
            $transitTime = is_string($transitDays) ? $transitDays : ($transitDays['minimumTransitTime'] ?? null);
            $deliveryDate = $detail['commit']['dateDetail']['dayFormat'] ?? $detail['commit']['dateDetail']['dayOfWeek'] ?? null;

            $serviceName = ($detail['serviceName'] ?? $serviceType).' (One Rate)';

            $results->push(new RateResponse(
                carrier: 'FedEx',
                serviceCode: $serviceType,
                serviceName: $serviceName,
                price: (float) ($ratedShipmentDetails['totalNetCharge'] ?? 0),
                deliveryDate: $deliveryDate,
                transitTime: $transitTime,
                metadata: [
                    'serviceType' => $serviceType,
                    'isOneRate' => true,
                    'fedexPackageType' => $package->fedexPackageType->value,
                ],
            ));
        }

        return $results;
    }

    private function sendCreateShipment(FedexConnector $connector, array $requestedShipment, ?CarrierAccount $account): Response
    {
        $apiRequest = new CreateShipment;
        $apiRequest->body()->set([
            'labelResponseOptions' => 'LABEL',
            'accountNumber' => [
                'value' => $this->resolveAccountNumber($account),
            ],
            'requestedShipment' => $requestedShipment,
        ]);

        try {
            return $connector->send($apiRequest);
        } catch (RequestException $e) {
            return $e->getResponse();
        }
    }

    private function buildContact(AddressData $address, ?string $fallbackPhone = null): array
    {
        $streetLines = array_filter(array_map(
            fn ($line): ?string => $line ? substr($line, 0, 35) : null,
            [$address->streetAddress, $address->streetAddress2],
        ));

        return [
            'contact' => array_filter([
                'personName' => trim($address->firstName.' '.$address->lastName),
                'companyName' => $address->company,
                'phoneNumber' => $address->phone ?? $fallbackPhone,
                'phoneExtension' => $address->phoneExtension,
            ]),
            'address' => [
                'streetLines' => array_values($streetLines),
                'city' => $address->city,
                'stateOrProvinceCode' => $address->stateOrProvince,
                'postalCode' => $address->postalCode,
                'countryCode' => $address->country,
            ],
        ];
    }

    /**
     * FedEx prints CUSTOMER_REFERENCE values on the label prefixed with "REF:".
     * Values are capped at 40 characters, and the label has room for three.
     *
     * @return array<string, mixed>
     */
    private function buildCustomerReferences(ShipRequest $request): array
    {
        $references = $this->labelReferences($request, maxLength: 40, maxCount: 3);

        if ($references === []) {
            return [];
        }

        return [
            'customerReferences' => array_map(fn (string $reference): array => [
                'customerReferenceType' => 'CUSTOMER_REFERENCE',
                'value' => $reference,
            ], $references),
        ];
    }

    /**
     * Build customs clearance detail for international shipments.
     *
     * @return array<string, mixed>
     */
    private function buildCustomsClearanceDetail(ShipRequest $request, ?CarrierAccount $account): array
    {
        $commodities = [];

        foreach ($request->customsItems as $item) {
            $totalValue = round($item->unitValue * $item->quantity, 2);

            $commodity = [
                'name' => mb_substr($item->description, 0, 35),
                'description' => mb_substr($item->description, 0, 450),
                'countryOfManufacture' => $item->countryOfOrigin ?? 'US',
                'quantity' => (string) $item->quantity,
                'quantityUnits' => 'PCS',
                'numberOfPieces' => (string) $item->quantity,
                'unitPrice' => [
                    'amount' => (string) $item->unitValue,
                    'currency' => 'USD',
                ],
                'customsValue' => [
                    'amount' => (string) $totalValue,
                    'currency' => 'USD',
                ],
                'weight' => [
                    'units' => 'LB',
                    'value' => (string) round($item->weight * $item->quantity, 2),
                ],
            ];

            // Add HS tariff number if available
            if ($item->hsTariffNumber) {
                $commodity['harmonizedCode'] = $item->hsTariffNumber;
            }

            $commodities[] = $commodity;
        }

        return [
            'commercialInvoice' => [
                'shipmentPurpose' => 'SOLD',
            ],
            'dutiesPayment' => [
                'paymentType' => 'SENDER',
                'payor' => [
                    'responsibleParty' => [
                        'address' => [
                            'countryCode' => $request->fromAddress->country,
                        ],
                        'accountNumber' => [
                            'value' => $this->resolveAccountNumber($account),
                        ],
                    ],
                ],
            ],
            'commodities' => $commodities,
        ];
    }

    /**
     * Check if we're using the FedEx sandbox environment.
     */
    private function isSandbox(): bool
    {
        return (bool) app(SettingsService::class)->get('sandbox_mode', false);
    }

    /**
     * Check if the request is for an international destination.
     */
    private function isInternational(RateRequest $request): bool
    {
        return $request->originCountry !== $request->destinationCountry;
    }

    /**
     * Whether this rate request has to be quoted locally rather than by FedEx.
     *
     * The sandbox rate API answers the canned domestic payload in
     * {@see self::buildRateApiRequest()} whatever it is asked, so an
     * international destination gets back domestic services and nothing
     * selectable. {@see FedexSandboxInternationalRates} stands in for it.
     */
    private function quotesInternationalLocally(RateRequest $request): bool
    {
        return $this->isSandbox() && $this->isInternational($request);
    }
}
