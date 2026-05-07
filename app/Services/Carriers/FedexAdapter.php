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
use App\Enums\FedexPackageType;
use App\Enums\ServiceCapability;
use App\Enums\TrackingStatus;
use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\FedexRegistrationProxyConnector;
use App\Http\Integrations\Fedex\Requests\CancelShipment as CancelShipmentRequest;
use App\Http\Integrations\Fedex\Requests\CreateShipment;
use App\Http\Integrations\Fedex\Requests\Rates;
use App\Http\Integrations\Fedex\Requests\TrackShipment;
use App\Models\Location;
use App\Models\Package;
use App\Services\Carriers\Concerns\HasDefaultServiceCapabilities;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;

class FedexAdapter implements CarrierAdapterInterface
{
    use HasDefaultServiceCapabilities;

    public function serviceCapability(string $serviceCode): ServiceCapability
    {
        return match ($serviceCode) {
            'saturday_delivery' => ServiceCapability::Supported,
            // FedEx does not accept cremated remains (service guide prohibition)
            'cremated_remains' => ServiceCapability::Prohibited,
            default => ServiceCapability::NotImplemented,
        };
    }

    /**
     * International service codes that need mock rates in sandbox mode.
     */
    private const INTERNATIONAL_SERVICE_CODES = [
        'FEDEX_INTERNATIONAL_PRIORITY',
        'FEDEX_INTERNATIONAL_ECONOMY',
        'INTERNATIONAL_FIRST',
        'INTERNATIONAL_PRIORITY',
        'INTERNATIONAL_ECONOMY',
    ];

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
        // // Check if we need to return mock rates for international sandbox testing
        // $internationalCodes = array_intersect($serviceCodes, self::INTERNATIONAL_SERVICE_CODES);
        // if ($this->isSandbox() && $this->isInternational($request) && ! empty($internationalCodes)) {
        //     logger()->debug('FedEx sandbox detected with international destination - returning mock rates', [
        //         'destination_country' => $request->destinationCountry,
        //         'service_codes' => $internationalCodes,
        //     ]);

        //     return $this->getMockInternationalRates($request, $internationalCodes);
        // }

        $prepared = $this->prepareRateRequest($request, $serviceCodes);

        if (! $prepared) {
            return collect();
        }

        $connector = FedexConnector::getFedexConnector();
        $apiRequest = $this->buildRateApiRequest($this->adjustRequestForSaturday($request, $serviceCodes), $serviceCodes);

        try {
            $response = $connector->send($apiRequest);
        } catch (RequestException $e) {
            // Saloon throws on 4xx/5xx when retries are exhausted. Pass the response
            // to parseRateResponse so the Saturday delivery retry logic can handle it.
            $response = $e->getResponse();
        }

        Log::channel('fedex-validation')->info('RATE REQUEST', ['payload' => $apiRequest->body()->all()]);
        Log::channel('fedex-validation')->info('RATE RESPONSE', ['status' => $response->status(), 'body' => $response->json()]);

        // Pass original $request so parseRateResponse knows Saturday was requested
        return $this->parseRateResponse($response, $request, $serviceCodes);
    }

    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
    {
        // TODO: restore sandbox international mock rates if needed
        // $internationalCodes = array_intersect($serviceCodes, self::INTERNATIONAL_SERVICE_CODES);
        // if ($this->isSandbox() && $this->isInternational($request) && ! empty($internationalCodes)) {
        //     return null;
        // }

        if (empty($request->packages)) {
            return null;
        }

        $connector = FedexConnector::getFedexConnector();
        $apiRequest = $this->buildRateApiRequest($this->adjustRequestForSaturday($request, $serviceCodes), $serviceCodes);
        $pendingRequest = $connector->createPendingRequest($apiRequest);

        return new PreparedRateRequest(
            pendingRequest: $pendingRequest,
            carrierName: 'FedEx',
        );
    }

    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
    {
        if (! $response->successful()) {
            // If Saturday delivery was requested, retry without it
            if ($request->saturdayDelivery) {
                $errors = $response->json('errors', []);
                $isSaturdayError = collect($errors)->contains(
                    fn ($e) => ($e['code'] ?? '') === 'SERVICE.PACKAGECOMBINATION.INVALID'
                );

                if ($isSaturdayError) {
                    logger()->info('FedEx Saturday delivery not available for this destination, retrying without');
                    $requestWithout = $this->withoutSaturdayDelivery($request);
                    $connector = FedexConnector::getFedexConnector();
                    $apiRequest = $this->buildRateApiRequest($requestWithout, $serviceCodes);
                    $retryResponse = $connector->send($apiRequest);

                    return $this->parseRateResponse($retryResponse, $requestWithout, $serviceCodes);
                }
            }

            $errors = $response->json('errors', []);
            logger()->error('FedEx API Error', [
                'status' => $response->status(),
                'errors' => $errors,
                'body' => $response->json(),
            ]);

            return collect();
        }

        $results = $this->extractRateDetails($response, $serviceCodes);

        // Mixed Saturday: initial request was sent without Saturday, now send
        // a follow-up with Saturday for eligible services and merge results
        if ($request->saturdayDelivery && $this->classifySaturdayEligibility($serviceCodes, $request) === 'mixed') {
            try {
                $connector = FedexConnector::getFedexConnector();
                $saturdayApiRequest = $this->buildRateApiRequest($request, $serviceCodes);
                $saturdayResponse = $connector->send($saturdayApiRequest);

                if ($saturdayResponse->successful()) {
                    $saturdayRates = $this->extractRateDetails($saturdayResponse, $serviceCodes);

                    if ($saturdayRates->isNotEmpty()) {
                        $saturdayServiceCodes = $saturdayRates->pluck('serviceCode')->unique()->all();
                        $results = $results->reject(
                            fn ($rate) => in_array($rate->serviceCode, $saturdayServiceCodes)
                                && empty($rate->metadata['isOneRate'])
                        );
                        $results = $results->merge($saturdayRates);
                    }
                } else {
                    logger()->warning('FedEx Saturday delivery rate request failed', [
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
            $oneRateResults = $this->fetchOneRateRates($request, $serviceCodes);
            $results = $results->merge($oneRateResults);
        }

        return $results;
    }

    /**
     * Build the FedEx rate API request.
     */
    private function buildRateApiRequest(RateRequest $request, array $serviceCodes): Rates
    {
        $package = $request->packages[0];
        $smartPostInfoDetail = $this->buildSmartPostInfoDetail($request, $serviceCodes);

        $apiRequest = new Rates;

        $apiRequest->body()->set([
            'accountNumber' => [
                'value' => app(SettingsService::class)->get('fedex.account_number'),
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
                    ],
                ],
                ...($smartPostInfoDetail ? [
                    'smartPostInfoDetail' => $smartPostInfoDetail,
                ] : []),
                ...($request->shipDate ? [
                    'shipDatestamp' => $request->shipDate->format('Y-m-d'),
                ] : []),
                ...($request->saturdayDelivery ? [
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

        logger()->debug('FedEx API Request', [
            'body' => $apiRequest->body(),
        ]);

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
        try {
            $connector = FedexConnector::getFedexConnector();

            $requestedShipment = [
                'shipper' => $this->buildContact($request->fromAddress),
                'recipients' => [
                    $this->buildContact($request->toAddress),
                ],
                ...($request->shipDate ? [
                    'shipDatestamp' => $request->shipDate->format('Y-m-d'),
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
                                'value' => app(SettingsService::class)->get('fedex.account_number'),
                            ],
                        ],
                    ],
                ],
                'labelSpecification' => array_filter([
                    'labelFormatType' => 'COMMON2D',
                    'imageType' => $request->labelFormat === 'zpl' ? 'ZPLII' : 'PDF',
                    'labelStockType' => 'STOCK_4X6',
                    'resolution' => $request->labelFormat === 'zpl' ? ($request->labelDpi === 300 ? 300 : 200) : null,
                ], fn ($v) => $v !== null),
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
                    ],
                ],
            ];

            if (($request->selectedRate->metadata['serviceType'] ?? null) === self::SMART_POST_SERVICE_CODE) {
                $smartPostInfoDetail = $this->buildShipmentSmartPostInfoDetail($request);

                if ($smartPostInfoDetail) {
                    $requestedShipment['smartPostInfoDetail'] = $smartPostInfoDetail;
                }
            }

            // Add customs clearance detail for international shipments
            if ($request->toAddress->country !== $request->fromAddress->country && ! empty($request->customsItems)) {
                $requestedShipment['customsClearanceDetail'] = $this->buildCustomsClearanceDetail($request);
            }

            // Build special service types
            $specialServiceTypes = [];
            if (! empty($request->selectedRate->metadata['isOneRate'])) {
                $specialServiceTypes[] = 'FEDEX_ONE_RATE';
            }
            $saturdayRequested = $request->saturdayDelivery;
            if ($saturdayRequested) {
                $specialServiceTypes[] = 'SATURDAY_DELIVERY';
            }
            if (! empty($specialServiceTypes)) {
                $requestedShipment['shipmentSpecialServices'] = [
                    'specialServiceTypes' => $specialServiceTypes,
                ];
            }

            $response = $this->sendCreateShipment($connector, $requestedShipment);
            $responseData = $response->json();

            // If Saturday delivery was rejected, retry without it
            $saturdayApplied = $saturdayRequested;
            if ($saturdayRequested && ! $response->successful()) {
                $errors = $responseData['errors'] ?? [];
                $isSaturdayError = collect($errors)->contains(function ($e) {
                    $code = $e['code'] ?? '';
                    $message = strtolower($e['message'] ?? '');
                    // Check message or code for Saturday references
                    if (str_contains($message, 'saturday') || str_contains(strtolower($code), 'saturday')) {
                        return true;
                    }
                    // Errors that reference SATURDAY_DELIVERY in parameterList
                    $saturdayInParams = collect($e['parameterList'] ?? [])->contains(
                        fn ($p) => ($p['value'] ?? '') === 'SATURDAY_DELIVERY'
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
                    logger()->info('FedEx Saturday delivery rejected, retrying without', [
                        'errors' => $errors,
                    ]);
                    $saturdayApplied = false;
                    // Remove only SATURDAY_DELIVERY, preserve other special services (e.g. FEDEX_ONE_RATE)
                    $existingTypes = $requestedShipment['shipmentSpecialServices']['specialServiceTypes'] ?? [];
                    $remainingTypes = array_values(array_filter($existingTypes, fn ($t) => $t !== 'SATURDAY_DELIVERY'));
                    if (empty($remainingTypes)) {
                        unset($requestedShipment['shipmentSpecialServices']);
                    } else {
                        $requestedShipment['shipmentSpecialServices']['specialServiceTypes'] = $remainingTypes;
                    }
                    $response = $this->sendCreateShipment($connector, $requestedShipment);
                    $responseData = $response->json();
                }
            }

            // Build the list of our service codes that were actually applied
            $appliedServices = [];
            if ($saturdayApplied) {
                $appliedServices[] = 'saturday_delivery';
            }

            if (! $response->successful()) {
                $errors = $responseData['errors'] ?? [];
                $errorMessage = ! empty($errors) ? ($errors[0]['message'] ?? 'Unknown FedEx error') : 'FedEx API error';
                logger()->error('FedEx createShipment API error', [
                    'status' => $response->status(),
                    'errors' => $errors,
                    'body' => $responseData,
                ]);

                return ShipResponse::failure($errorMessage);
            }

            $shipmentData = $responseData['output']['transactionShipments'][0] ?? null;

            if (! $shipmentData) {
                logger()->error('FedEx createShipment missing shipment data', [
                    'output' => $responseData['output'] ?? null,
                ]);

                return ShipResponse::failure('FedEx response missing shipment data');
            }

            $trackingNumber = $shipmentData['masterTrackingNumber']
                ?? $shipmentData['pieceResponses'][0]['trackingNumber']
                ?? null;

            if (empty($trackingNumber)) {
                logger()->error('FedEx createShipment missing tracking number', [
                    'shipmentData' => $shipmentData,
                ]);

                return ShipResponse::failure('FedEx response missing tracking number');
            }

            $labelData = $shipmentData['pieceResponses'][0]['packageDocuments'][0]['encodedLabel'] ?? null;

            if (empty($labelData)) {
                logger()->error('FedEx createShipment missing label data', [
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
            );
        } catch (\Exception $e) {
            logger()->error('FedEx createShipment error', ['error' => $e->getMessage()]);

            return ShipResponse::failure($e->getMessage());
        }
    }

    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
    {
        try {
            $connector = FedexConnector::getFedexConnector();

            $apiRequest = new CancelShipmentRequest;
            $apiRequest->body()->set([
                'accountNumber' => [
                    'value' => app(SettingsService::class)->get('fedex.account_number'),
                ],
                'trackingNumber' => $trackingNumber,
            ]);

            $response = $connector->send($apiRequest);

            if ($response->successful()) {
                return CancelResponse::success('FedEx shipment cancelled.');
            }

            return CancelResponse::failure('FedEx returned status '.$response->status());
        } catch (\Exception $e) {
            return CancelResponse::failure($e->getMessage());
        }
    }

    public function trackShipment(Package $package): TrackShipmentResponse
    {
        try {
            if (config('services.oauth.broker_url')) {
                $connector = new FedexRegistrationProxyConnector;
            } else {
                $connector = FedexConnector::getFedexConnector();
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
                ->filter(fn ($event) => is_array($event))
                ->map(fn (array $event) => $this->mapTrackingEvent($event))
                ->sortByDesc(fn (TrackingEventData $event) => $event->timestamp?->getTimestamp() ?? 0)
                ->values()
                ->all();

            $estimatedDeliveryAt = $this->parseFedexDate(
                data_get($trackResult, 'estimatedDeliveryTimeWindow.window.ends')
                ?? data_get($trackResult, 'dateAndTimes.0.dateTime')
                ?? data_get($trackResult, 'estimatedDeliveryTimestamp')
            );

            $deliveredAt = $this->resolveDeliveredAt($events, $statusCode, $trackResult);
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
            logger()->error('FedEx trackShipment error', [
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

    /**
     * @param  array<int, TrackingEventData>  $events
     * @param  array<string, mixed>  $trackResult
     */
    private function resolveDeliveredAt(array $events, string $statusCode, array $trackResult): ?CarbonImmutable
    {
        $deliveredEvent = collect($events)->first(fn (TrackingEventData $event) => $event->statusCode === 'DL');

        if ($deliveredEvent instanceof TrackingEventData) {
            return $deliveredEvent->timestamp;
        }

        if ($this->mapTrackingStatus($statusCode, (string) data_get($trackResult, 'latestStatusDetail.description', '')) === TrackingStatus::Delivered) {
            return $this->parseFedexDate(
                data_get($trackResult, 'dateAndTimes.0.dateTime') ?? data_get($trackResult, 'actualDeliveryTimestamp')
            );
        }

        return null;
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

    public function isConfigured(): bool
    {
        $settings = app(SettingsService::class);

        return filled($settings->get('fedex.api_key'))
            && filled($settings->get('fedex.api_secret'))
            && filled($settings->get('fedex.account_number'));
    }

    public function supportsMultiPackage(): bool
    {
        return true;
    }

    public function supportsManifest(): bool
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
    private function withoutSaturdayDelivery(RateRequest $request): RateRequest
    {
        return new RateRequest(
            originPostalCode: $request->originPostalCode,
            destinationPostalCode: $request->destinationPostalCode,
            originCountry: $request->originCountry,
            destinationCountry: $request->destinationCountry,
            destinationCity: $request->destinationCity,
            destinationStateOrProvince: $request->destinationStateOrProvince,
            residential: $request->residential,
            packages: $request->packages,
            saturdayDelivery: false,
            locationId: $request->locationId,
            shipDate: $request->shipDate,
        );
    }

    /**
     * Extract rate details from a successful FedEx rate response.
     * Core parsing loop used by parseRateResponse and mixed Saturday handling.
     */
    private function extractRateDetails(Response $response, array $serviceCodes): Collection
    {
        $rateReplyDetails = $response->json('output.rateReplyDetails', []);

        if (! is_array($rateReplyDetails)) {
            logger()->warning('FedEx API returned invalid rateReplyDetails', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return collect();
        }

        $returnedServiceTypes = array_map(fn ($d) => $d['serviceType'] ?? 'unknown', $rateReplyDetails);
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
    private function classifySaturdayEligibility(array $serviceCodes, ?RateRequest $request = null): string
    {
        $today = ($request?->shipDate ?? now())->dayOfWeek;

        // No service filter = FedEx returns all service types = always mixed
        if (empty($serviceCodes)) {
            return 'mixed';
        }

        $eligible = 0;
        $ineligible = 0;

        foreach ($serviceCodes as $code) {
            $saturdayDay = self::SATURDAY_DELIVERY_DAY_MAP[$code] ?? null;
            if ($saturdayDay === $today) {
                $eligible++;
            } else {
                $ineligible++;
            }
        }

        if ($ineligible === 0) {
            return 'all';
        }

        if ($eligible === 0) {
            return 'none';
        }

        return 'mixed';
    }

    /**
     * Adjust the rate request for Saturday delivery based on service eligibility.
     * For 'all' eligible: keep Saturday. For 'none' or 'mixed': strip it
     * (mixed sends a follow-up Saturday request in parseRateResponse).
     */
    private function adjustRequestForSaturday(RateRequest $request, array $serviceCodes): RateRequest
    {
        if ($request->saturdayDelivery && $this->classifySaturdayEligibility($serviceCodes, $request) !== 'all') {
            return $this->withoutSaturdayDelivery($request);
        }

        return $request;
    }

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
    private function fetchOneRateRates(RateRequest $request, array $serviceCodes): Collection
    {
        try {
            $connector = FedexConnector::getFedexConnector();
            $apiRequest = $this->buildOneRateApiRequest($request);
            $response = $connector->send($apiRequest);

            if (! $response->successful()) {
                logger()->warning('FedEx One Rate request failed', [
                    'status' => $response->status(),
                    'errors' => $response->json('errors', []),
                ]);

                return collect();
            }

            return $this->parseOneRateResponse($response, $request, $serviceCodes);
        } catch (\Exception $e) {
            logger()->warning('FedEx One Rate request error', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * Build the FedEx One Rate API request.
     */
    private function buildOneRateApiRequest(RateRequest $request): Rates
    {
        $package = $request->packages[0];

        $apiRequest = new Rates;
        $apiRequest->body()->set([
            'accountNumber' => [
                'value' => app(SettingsService::class)->get('fedex.account_number'),
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
                    'shipDatestamp' => $request->shipDate->format('Y-m-d'),
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
                        $request->saturdayDelivery ? 'SATURDAY_DELIVERY' : null,
                    ]),
                ],
            ],
        ]);

        logger()->debug('FedEx One Rate API Request', [
            'body' => $apiRequest->body(),
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

    private function sendCreateShipment($connector, array $requestedShipment): Response
    {
        $apiRequest = new CreateShipment;
        $apiRequest->body()->set([
            'labelResponseOptions' => 'LABEL',
            'accountNumber' => [
                'value' => app(SettingsService::class)->get('fedex.account_number'),
            ],
            'requestedShipment' => $requestedShipment,
        ]);

        try {
            return $connector->send($apiRequest);
        } catch (RequestException $e) {
            return $e->getResponse();
        }
    }

    private function buildContact(AddressData $address): array
    {
        $streetLines = array_filter(array_map(
            fn ($line) => $line ? substr($line, 0, 35) : null,
            [$address->streetAddress, $address->streetAddress2],
        ));

        return [
            'contact' => array_filter([
                'personName' => trim($address->firstName.' '.$address->lastName),
                'companyName' => $address->company,
                'phoneNumber' => $address->phone,
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
     * Build customs clearance detail for international shipments.
     *
     * @return array<string, mixed>
     */
    private function buildCustomsClearanceDetail(ShipRequest $request): array
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
                            'value' => app(SettingsService::class)->get('fedex.account_number'),
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
     * Generate mock international rates for sandbox testing.
     *
     * @param  array<string>  $serviceCodes
     * @return Collection<int, RateResponse>
     */
    private function getMockInternationalRates(RateRequest $request, array $serviceCodes): Collection
    {
        $package = $request->packages[0] ?? null;
        $baseWeight = $package?->weight ?? 1.0;

        $mockRates = [
            'FEDEX_INTERNATIONAL_PRIORITY' => [
                'serviceName' => 'FedEx International Priority',
                'basePrice' => 45.00,
                'transitDays' => '1-3 business days',
                'deliveryDay' => 'WEDNESDAY',
            ],
            'FEDEX_INTERNATIONAL_ECONOMY' => [
                'serviceName' => 'FedEx International Economy',
                'basePrice' => 32.00,
                'transitDays' => '4-6 business days',
                'deliveryDay' => 'FRIDAY',
            ],
            'INTERNATIONAL_FIRST' => [
                'serviceName' => 'FedEx International First',
                'basePrice' => 75.00,
                'transitDays' => '1-2 business days',
                'deliveryDay' => 'TUESDAY',
            ],
            'INTERNATIONAL_PRIORITY' => [
                'serviceName' => 'FedEx International Priority',
                'basePrice' => 45.00,
                'transitDays' => '1-3 business days',
                'deliveryDay' => 'WEDNESDAY',
            ],
            'INTERNATIONAL_ECONOMY' => [
                'serviceName' => 'FedEx International Economy',
                'basePrice' => 32.00,
                'transitDays' => '4-6 business days',
                'deliveryDay' => 'FRIDAY',
            ],
        ];

        $results = collect();

        foreach ($serviceCodes as $serviceCode) {
            if (! isset($mockRates[$serviceCode])) {
                continue;
            }

            $rate = $mockRates[$serviceCode];
            // Scale price by weight (roughly $5 per pound over 1 lb)
            $price = $rate['basePrice'] + max(0, ($baseWeight - 1) * 5);

            $results->push(new RateResponse(
                carrier: 'FedEx',
                serviceCode: $serviceCode,
                serviceName: $rate['serviceName'],
                price: round($price, 2),
                deliveryDate: $rate['deliveryDay'],
                transitTime: $rate['transitDays'],
                metadata: [
                    'serviceType' => $serviceCode,
                    'isMockRate' => true,
                    'sandboxNote' => 'Mock rate generated for sandbox testing of international shipments',
                ],
            ));
        }

        return $results;
    }
}
