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
use App\Enums\ServiceCapability;
use App\Enums\TrackingStatus;
use App\Http\Integrations\Ups\Requests\CreateShipment;
use App\Http\Integrations\Ups\Requests\Rate;
use App\Http\Integrations\Ups\Requests\TrackShipment;
use App\Http\Integrations\Ups\Requests\VoidShipment;
use App\Http\Integrations\Ups\UpsConnector;
use App\Models\Package;
use App\Services\Carriers\Concerns\HasDefaultServiceCapabilities;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;

class UpsAdapter implements CarrierAdapterInterface
{
    use HasDefaultServiceCapabilities;

    public function serviceCapability(string $serviceCode): ServiceCapability
    {
        return match ($serviceCode) {
            'saturday_delivery' => ServiceCapability::Supported,
            default => ServiceCapability::NotImplemented,
        };
    }

    /**
     * UPS service code to human-readable name mapping.
     *
     * @var array<string, string>
     */
    private const SERVICE_NAMES = [
        '01' => 'UPS Next Day Air',
        '02' => 'UPS 2nd Day Air',
        '03' => 'UPS Ground',
        '07' => 'UPS Worldwide Express',
        '08' => 'UPS Worldwide Expedited',
        '11' => 'UPS Standard',
        '12' => 'UPS 3 Day Select',
        '13' => 'UPS Next Day Air Saver',
        '14' => 'UPS Next Day Air Early',
    ];

    /**
     * Map UPS service codes to the day of week when Saturday delivery applies.
     * dayOfWeek values: 3=Wednesday, 4=Thursday, 5=Friday
     * Ground (03) excluded — variable transit times make day mapping impractical.
     */
    private const SATURDAY_DELIVERY_DAY_MAP = [
        '14' => 5,  // Next Day Air Early — Friday → Saturday
        '01' => 5,  // Next Day Air — Friday → Saturday
        '13' => 5,  // Next Day Air Saver — Friday → Saturday
        '02' => 4,  // 2nd Day Air — Thursday → Saturday
        '12' => 3,  // 3 Day Select — Wednesday → Saturday
    ];

    public function getCarrierName(): string
    {
        return 'UPS';
    }

    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        try {
            $prepared = $this->prepareRateRequest($request, $serviceCodes);

            if (! $prepared) {
                return collect();
            }

            $connector = UpsConnector::getAuthenticatedConnector();
            $apiRequest = $this->buildRateApiRequest($this->adjustRequestForSaturday($request, $serviceCodes));
            $response = $connector->send($apiRequest);

            // Pass original $request so parseRateResponse knows Saturday was requested
            return $this->parseRateResponse($response, $request, $serviceCodes);
        } catch (\Exception $e) {
            logger()->error('UPS getRates error', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
    {
        if (empty($request->packages)) {
            return null;
        }

        $connector = UpsConnector::getAuthenticatedConnector();
        $apiRequest = $this->buildRateApiRequest($this->adjustRequestForSaturday($request, $serviceCodes));
        $pendingRequest = $connector->createPendingRequest($apiRequest);

        return new PreparedRateRequest(
            pendingRequest: $pendingRequest,
            carrierName: 'UPS',
        );
    }

    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
    {
        if (! $response->successful()) {
            logger()->error('UPS Rate API Error', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return collect();
        }

        $results = $this->extractRateDetails($response, $serviceCodes);

        // Mixed Saturday: initial request was sent without Saturday, now send
        // a follow-up with Saturday for eligible services and merge results
        if ($request->saturdayDelivery && $this->classifySaturdayEligibility($serviceCodes, $request) === 'mixed') {
            try {
                $connector = UpsConnector::getAuthenticatedConnector();
                $saturdayApiRequest = $this->buildRateApiRequest($request);
                $saturdayResponse = $connector->send($saturdayApiRequest);

                if ($saturdayResponse->successful()) {
                    $saturdayRates = $this->extractRateDetails($saturdayResponse, $serviceCodes);

                    if ($saturdayRates->isNotEmpty()) {
                        $saturdayServiceCodes = $saturdayRates->pluck('serviceCode')->unique()->all();
                        $results = $results->reject(
                            fn ($rate) => in_array($rate->serviceCode, $saturdayServiceCodes)
                        );
                        $results = $results->merge($saturdayRates);
                    }
                } else {
                    logger()->warning('UPS Saturday delivery rate request failed', [
                        'status' => $saturdayResponse->status(),
                        'errors' => $saturdayResponse->json(),
                    ]);
                }
            } catch (\Exception $e) {
                logger()->warning('UPS Saturday delivery rate request error', ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    public function supportsTracking(): bool
    {
        return true;
    }

    public function trackShipment(Package $package): TrackShipmentResponse
    {
        try {
            $connector = UpsConnector::getAuthenticatedConnector();
            $trackRequest = new TrackShipment($package->tracking_number);
            $requestUri = rtrim($connector->resolveBaseUrl(), '/').$trackRequest->resolveEndpoint();

            Log::channel('ups-validation')->info('TRACK REQUEST', [
                'tracking_number' => $package->tracking_number,
                'uri' => $requestUri,
                'headers' => $trackRequest->headers()->all(),
                'query' => $trackRequest->query()->all(),
            ]);

            $response = $connector->send($trackRequest);
            $rawResponse = $this->decodeJsonSafely($response);

            Log::channel('ups-validation')->info('TRACK RESPONSE', [
                'tracking_number' => $package->tracking_number,
                'uri' => $requestUri,
                'status' => $response->status(),
                'body' => $rawResponse,
            ]);

            if (! $response->successful()) {
                return TrackShipmentResponse::failure(
                    data_get($rawResponse, 'response.errors.0.message')
                        ?? data_get($rawResponse, 'errors.0.message')
                        ?? 'UPS tracking request failed.',
                    ['raw' => $rawResponse],
                );
            }

            $packageData = data_get($rawResponse, 'trackResponse.shipment.0.package.0');

            if (! is_array($packageData)) {
                return TrackShipmentResponse::failure('UPS returned an unexpected tracking response.', [
                    'raw' => $rawResponse,
                ]);
            }

            $statusLabel = data_get($packageData, 'currentStatus.description')
                ?? data_get($packageData, 'currentStatus.simplifiedTextDescription')
                ?? data_get($packageData, 'statusDescription')
                ?? 'Tracking update available';

            $events = collect($packageData['activity'] ?? [])
                ->filter(fn ($event) => is_array($event))
                ->map(fn (array $event): TrackingEventData => $this->mapTrackingEvent($event))
                ->sortByDesc(fn (TrackingEventData $event) => $event->timestamp?->getTimestamp() ?? 0)
                ->values()
                ->all();

            $estimatedDeliveryAt = $this->parseEstimatedDelivery($packageData);
            $deliveredAt = $this->resolveDeliveredAt($events, $packageData);
            $status = $this->mapTrackingStatus($packageData, $events);

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

            Log::channel('ups-validation')->info('TRACK RESPONSE', [
                'tracking_number' => $package->tracking_number,
                'uri' => rtrim(UpsConnector::getAuthenticatedConnector()->resolveBaseUrl(), '/').(new TrackShipment($package->tracking_number))->resolveEndpoint(),
                'status' => $e->getResponse()->status(),
                'body' => $rawResponse,
            ]);

            return TrackShipmentResponse::failure(
                data_get($rawResponse, 'response.errors.0.message')
                    ?? data_get($rawResponse, 'errors.0.message')
                    ?? $e->getMessage()
                    ?? 'UPS tracking request failed.',
                ['raw' => $rawResponse],
            );
        } catch (\Throwable $e) {
            logger()->error('UPS trackShipment error', [
                'tracking_number' => $package->tracking_number,
                'error' => $e->getMessage(),
            ]);

            return TrackShipmentResponse::failure('Unable to fetch UPS tracking information.');
        }
    }

    /**
     * Extract rate details from a successful UPS rate response.
     */
    private function extractRateDetails(Response $response, array $serviceCodes): Collection
    {
        $ratedShipments = $response->json('RateResponse.RatedShipment', []);

        if (! is_array($ratedShipments)) {
            logger()->warning('UPS Rate API returned invalid RatedShipment', [
                'body' => $response->json(),
            ]);

            return collect();
        }

        // Normalize to array of shipments (single result may not be wrapped)
        if (isset($ratedShipments['Service'])) {
            $ratedShipments = [$ratedShipments];
        }

        $returnedServiceCodes = array_map(fn ($s) => $s['Service']['Code'] ?? 'unknown', $ratedShipments);
        logger()->debug('UPS rate response filtering', [
            'returned_services' => $returnedServiceCodes,
            'requested_codes' => $serviceCodes,
        ]);

        $results = collect();

        foreach ($ratedShipments as $shipment) {
            $serviceCode = $shipment['Service']['Code'] ?? null;

            if (! $serviceCode) {
                continue;
            }

            if (! empty($serviceCodes) && ! in_array($serviceCode, $serviceCodes)) {
                continue;
            }

            $totalCharges = (float) ($shipment['TotalCharges']['MonetaryValue'] ?? 0);
            $serviceName = self::SERVICE_NAMES[$serviceCode] ?? ('UPS Service '.$serviceCode);

            // Extract transit/delivery info from TimeInTransit if available
            $transitDays = $shipment['TimeInTransit']['ServiceSummary']['EstimatedArrival']['BusinessDaysInTransit'] ?? null;
            $deliveryDate = $shipment['TimeInTransit']['ServiceSummary']['EstimatedArrival']['Arrival']['Date'] ?? null;

            // Also check GuaranteedDelivery
            if (! $transitDays) {
                $transitDays = $shipment['GuaranteedDelivery']['BusinessDaysInTransit'] ?? null;
            }

            $transitTime = $transitDays ? $transitDays.' business day'.($transitDays != 1 ? 's' : '') : null;

            // Format delivery date if available (UPS returns YYYYMMDD)
            if ($deliveryDate && strlen($deliveryDate) === 8) {
                $deliveryDate = substr($deliveryDate, 0, 4).'-'.substr($deliveryDate, 4, 2).'-'.substr($deliveryDate, 6, 2);
            }

            $results->push(new RateResponse(
                carrier: 'UPS',
                serviceCode: $serviceCode,
                serviceName: $serviceName,
                price: $totalCharges,
                deliveryDate: $deliveryDate,
                transitTime: $transitTime,
                metadata: [
                    'serviceCode' => $serviceCode,
                ],
            ));
        }

        return $results;
    }

    /**
     * Build the UPS rate API request.
     */
    private function buildRateApiRequest(RateRequest $request): Rate
    {
        $package = $request->packages[0];

        $apiRequest = new Rate;
        $apiRequest->body()->set([
            'RateRequest' => [
                'Request' => [
                    'SubVersion' => '2403',
                    'TransactionReference' => [
                        'CustomerContext' => 'Rating',
                    ],
                ],
                'Shipment' => [
                    'Shipper' => [
                        'Address' => [
                            'PostalCode' => $request->originPostalCode,
                            'CountryCode' => 'US',
                        ],
                    ],
                    'ShipTo' => [
                        'Address' => array_filter([
                            'City' => $request->destinationCity,
                            'StateProvinceCode' => $request->destinationStateOrProvince,
                            'PostalCode' => $request->destinationPostalCode,
                            'CountryCode' => $request->destinationCountry,
                            'ResidentialAddressIndicator' => $request->residential ? '' : null,
                        ], fn ($v) => $v !== null),
                    ],
                    'ShipFrom' => [
                        'Address' => [
                            'PostalCode' => $request->originPostalCode,
                            'CountryCode' => 'US',
                        ],
                    ],
                    'Package' => [
                        'PackagingType' => [
                            'Code' => '02',
                            'Description' => 'Customer Supplied Package',
                        ],
                        'PackageWeight' => [
                            'UnitOfMeasurement' => [
                                'Code' => 'LBS',
                            ],
                            'Weight' => (string) $package->weight,
                        ],
                    ],
                    'DeliveryTimeInformation' => array_filter([
                        'PackageBillType' => '03',
                        'Pickup' => $request->shipDate ? [
                            'Date' => $request->shipDate->format('Ymd'),
                        ] : null,
                    ]),
                    ...($request->saturdayDelivery ? [
                        'ShipmentServiceOptions' => [
                            'SaturdayDeliveryIndicator' => '',
                        ],
                    ] : []),
                ],
            ],
        ]);

        logger()->debug('UPS Rate API Request', [
            'body' => $apiRequest->body(),
        ]);

        return $apiRequest;
    }

    public function createShipment(ShipRequest $request): ShipResponse
    {
        try {
            $connector = UpsConnector::getAuthenticatedConnector();

            $serviceCode = $request->selectedRate->metadata['serviceCode'] ?? $request->selectedRate->serviceCode;

            $shipment = [
                'Description' => 'Shipment',
                'Shipper' => [
                    'Name' => trim($request->fromAddress->company ?: $request->fromAddress->firstName.' '.$request->fromAddress->lastName),
                    'ShipperNumber' => app(SettingsService::class)->get('ups.account_number'),
                    'Address' => $this->buildAddress($request->fromAddress),
                ],
                'ShipTo' => [
                    'Name' => trim($request->toAddress->firstName.' '.$request->toAddress->lastName),
                    'Address' => $this->buildAddress($request->toAddress),
                ],
                'ShipFrom' => [
                    'Name' => trim($request->fromAddress->company ?: $request->fromAddress->firstName.' '.$request->fromAddress->lastName),
                    'Address' => $this->buildAddress($request->fromAddress),
                ],
                'PaymentInformation' => [
                    'ShipmentCharge' => [
                        [
                            'Type' => '01',
                            'BillShipper' => [
                                'AccountNumber' => app(SettingsService::class)->get('ups.account_number'),
                            ],
                        ],
                    ],
                ],
                'Service' => [
                    'Code' => $serviceCode,
                ],
                'Package' => [
                    [
                        'Packaging' => [
                            'Code' => '02',
                            'Description' => 'Customer Supplied Package',
                        ],
                        'PackageWeight' => [
                            'UnitOfMeasurement' => [
                                'Code' => 'LBS',
                            ],
                            'Weight' => (string) $request->packageData->weight,
                        ],
                        'Dimensions' => [
                            'UnitOfMeasurement' => [
                                'Code' => 'IN',
                            ],
                            'Length' => (string) (int) $request->packageData->length,
                            'Width' => (string) (int) $request->packageData->width,
                            'Height' => (string) (int) $request->packageData->height,
                        ],
                    ],
                ],
            ];

            // Add Saturday delivery if requested
            if ($request->saturdayDelivery) {
                $shipment['ShipmentServiceOptions'] = array_merge(
                    $shipment['ShipmentServiceOptions'] ?? [],
                    ['SaturdayDeliveryIndicator' => ''],
                );
            }

            // Add international forms for non-US destinations
            if ($request->toAddress->country !== 'US' && ! empty($request->customsItems)) {
                $shipment['InternationalForms'] = $this->buildCustomsDetail($request);
            }

            $response = $this->sendCreateShipment($connector, $shipment, $request, $serviceCode);
            $responseData = $response->json();

            // If Saturday delivery was rejected, retry without it
            if ($request->saturdayDelivery && ! $response->successful()) {
                $errorJson = json_encode($responseData);
                if (str_contains(strtolower($errorJson), 'saturday')) {
                    logger()->info('UPS Saturday delivery rejected, retrying without', [
                        'body' => $responseData,
                    ]);
                    unset($shipment['ShipmentServiceOptions']['SaturdayDeliveryIndicator']);
                    if (empty($shipment['ShipmentServiceOptions'])) {
                        unset($shipment['ShipmentServiceOptions']);
                    }
                    $response = $this->sendCreateShipment($connector, $shipment, $request, $serviceCode);
                    $responseData = $response->json();
                }
            }

            if (! $response->successful()) {
                $errorMessage = $responseData['response']['errors'][0]['message']
                    ?? $responseData['errors'][0]['message']
                    ?? 'UPS API error';
                logger()->error('UPS createShipment API error', [
                    'status' => $response->status(),
                    'body' => $responseData,
                ]);

                return ShipResponse::failure($errorMessage);
            }

            $shipmentResults = $responseData['ShipmentResponse']['ShipmentResults'] ?? null;

            if (! $shipmentResults) {
                logger()->error('UPS createShipment missing ShipmentResults', [
                    'body' => $responseData,
                ]);

                return ShipResponse::failure('UPS response missing shipment results');
            }

            $trackingNumber = $shipmentResults['ShipmentIdentificationNumber'] ?? null;

            if (empty($trackingNumber)) {
                logger()->error('UPS createShipment missing tracking number', [
                    'shipmentResults' => $shipmentResults,
                ]);

                return ShipResponse::failure('UPS response missing tracking number');
            }

            // Package results may be a single object or array
            $packageResults = $shipmentResults['PackageResults'] ?? [];
            if (isset($packageResults['TrackingNumber'])) {
                $packageResults = [$packageResults];
            }

            $labelData = $packageResults[0]['ShippingLabel']['GraphicImage'] ?? null;

            if (empty($labelData)) {
                logger()->error('UPS createShipment missing label data', [
                    'packageResults' => $packageResults,
                ]);

                return ShipResponse::failure('UPS response missing label data');
            }

            // UPS ZPL is always 203 DPI; scale to 300 DPI if requested
            if ($request->labelFormat === 'zpl' && $request->labelDpi === 300) {
                $decoded = base64_decode($labelData);
                $decoded = preg_replace('/\^XA/', '^XA^JMA', $decoded, 1);
                $labelData = base64_encode($decoded);
            }

            $totalCharge = (float) ($shipmentResults['ShipmentCharges']['TotalCharges']['MonetaryValue']
                ?? $request->selectedRate->price);

            $isZpl = $request->labelFormat === 'zpl';

            return ShipResponse::success(
                trackingNumber: $trackingNumber,
                cost: $totalCharge,
                carrier: 'UPS',
                service: $request->selectedRate->serviceName,
                labelData: $labelData,
                labelOrientation: $isZpl ? 'portrait' : 'landscape',
                labelFormat: $isZpl ? 'zpl' : 'image',
                labelDpi: $request->labelDpi,
                shipDate: $request->shipDate,
            );
        } catch (\Exception $e) {
            logger()->error('UPS createShipment error', ['error' => $e->getMessage()]);

            return ShipResponse::failure($e->getMessage());
        }
    }

    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
    {
        try {
            $connector = UpsConnector::getAuthenticatedConnector();

            $apiRequest = new VoidShipment($trackingNumber);

            $response = $connector->send($apiRequest);

            if ($response->successful()) {
                $status = $response->json('VoidShipmentResponse.SummaryResult.Status.Description');

                return CancelResponse::success($status ?? 'UPS shipment voided.');
            }

            $errorMessage = $response->json('response.errors.0.message')
                ?? $response->json('errors.0.message')
                ?? 'UPS returned status '.$response->status();

            return CancelResponse::failure($errorMessage);
        } catch (\Exception $e) {
            return CancelResponse::failure($e->getMessage());
        }
    }

    public function isConfigured(): bool
    {
        $settings = app(SettingsService::class);

        return filled($settings->get('ups.client_id'))
            && filled($settings->get('ups.client_secret'))
            && filled($settings->get('ups.account_number'));
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
     * Classify Saturday delivery eligibility for the requested service codes.
     * Returns 'all', 'none', or 'mixed' based on today's day of week.
     */
    private function classifySaturdayEligibility(array $serviceCodes, ?RateRequest $request = null): string
    {
        $today = ($request?->shipDate ?? now())->dayOfWeek;

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
     */
    private function adjustRequestForSaturday(RateRequest $request, array $serviceCodes): RateRequest
    {
        if ($request->saturdayDelivery && $this->classifySaturdayEligibility($serviceCodes, $request) !== 'all') {
            return $this->withoutSaturdayDelivery($request);
        }

        return $request;
    }

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

    private function sendCreateShipment($connector, array $shipment, ShipRequest $request, string $serviceCode): Response
    {
        $apiRequest = new CreateShipment;
        $apiRequest->body()->set([
            'ShipmentRequest' => [
                'Request' => [
                    'SubVersion' => '2409',
                    'RequestOption' => 'nonvalidate',
                    'TransactionReference' => [
                        'CustomerContext' => 'Shipping',
                    ],
                ],
                'Shipment' => $shipment,
                'LabelSpecification' => [
                    'LabelImageFormat' => [
                        'Code' => $request->labelFormat === 'zpl' ? 'ZPL' : 'GIF',
                    ],
                    'LabelStockSize' => [
                        'Height' => '6',
                        'Width' => '4',
                    ],
                ],
            ],
        ]);

        logger()->debug('UPS CreateShipment API Request', [
            'serviceCode' => $serviceCode,
        ]);

        return $connector->send($apiRequest);
    }

    private function buildAddress(AddressData $address): array
    {
        $addressLines = array_values(array_filter([
            $address->streetAddress,
            $address->streetAddress2,
        ]));

        return array_filter([
            'AddressLine' => $addressLines,
            'City' => $address->city,
            'StateProvinceCode' => $address->stateOrProvince,
            'PostalCode' => $address->postalCode,
            'CountryCode' => $address->country,
        ]);
    }

    /**
     * Build UPS InternationalForms for international shipments.
     *
     * @return array<string, mixed>
     */
    private function buildCustomsDetail(ShipRequest $request): array
    {
        $products = [];

        foreach ($request->customsItems as $item) {
            $totalValue = round($item->unitValue * $item->quantity, 2);

            $product = [
                'Description' => mb_substr($item->description, 0, 35),
                'Unit' => [
                    'Number' => (string) $item->quantity,
                    'UnitOfMeasurement' => [
                        'Code' => 'PCS',
                    ],
                    'Value' => (string) $totalValue,
                ],
                'OriginCountryCode' => $item->countryOfOrigin ?? 'US',
                'ProductWeight' => [
                    'UnitOfMeasurement' => [
                        'Code' => 'LBS',
                    ],
                    'Weight' => (string) round($item->weight * $item->quantity, 2),
                ],
            ];

            if ($item->hsTariffNumber) {
                $product['CommodityCode'] = $item->hsTariffNumber;
            }

            $products[] = $product;
        }

        return [
            'FormType' => ['Code' => '01', 'Description' => 'Invoice'],
            'InvoiceDate' => now()->format('Ymd'),
            'ReasonForExport' => 'SALE',
            'CurrencyCode' => 'USD',
            'Product' => $products,
        ];
    }

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
     * @param  array<string, mixed>  $packageData
     * @param  array<int, TrackingEventData>  $events
     */
    private function mapTrackingStatus(array $packageData, array $events): TrackingStatus
    {
        $statusText = strtoupper(implode(' ', array_filter([
            data_get($packageData, 'currentStatus.description'),
            data_get($packageData, 'currentStatus.simplifiedTextDescription'),
            data_get($packageData, 'statusDescription'),
        ])));

        if (str_contains($statusText, 'DELIVERED')) {
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
            || str_contains($statusText, 'HOLD')
            || str_contains($statusText, 'PICKUP')
            || str_contains($statusText, 'CUSTOMS')
        ) {
            return TrackingStatus::Exception;
        }

        if (
            str_contains($statusText, 'LABEL CREATED')
            || str_contains($statusText, 'SHIPMENT READY')
            || str_contains($statusText, 'ORDER PROCESSED')
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
            data_get($event, 'location.address.city'),
            data_get($event, 'location.address.stateProvince'),
            data_get($event, 'location.address.countryCode'),
        ]);

        return new TrackingEventData(
            timestamp: $this->parseActivityTimestamp($event),
            location: empty($locationParts) ? null : implode(', ', $locationParts),
            description: data_get($event, 'status.description')
                ?? data_get($event, 'status.simplifiedTextDescription')
                ?? 'Tracking event',
            statusCode: data_get($event, 'status.statusCode'),
            status: data_get($event, 'status.type'),
            raw: $event,
        );
    }

    /**
     * @param  array<string, mixed>  $packageData
     */
    private function parseEstimatedDelivery(array $packageData): ?CarbonImmutable
    {
        $deliveryDate = collect($packageData['deliveryDate'] ?? [])
            ->first(fn ($date) => is_array($date) && in_array(($date['type'] ?? null), ['SDD', 'RDD'], true));

        $deliveryDateValue = is_array($deliveryDate) ? ($deliveryDate['date'] ?? null) : null;
        $deliveryTime = $packageData['deliveryTime'] ?? [];
        $endTime = is_array($deliveryTime) ? ($deliveryTime['endTime'] ?? null) : null;

        return $this->parseUpsDateTime($deliveryDateValue, $endTime);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function parseActivityTimestamp(array $event): ?CarbonImmutable
    {
        $gmtDate = $event['gmtDate'] ?? null;
        $gmtTime = $event['gmtTime'] ?? null;
        $gmtOffset = $event['gmtOffset'] ?? '+00:00';

        if (is_string($gmtDate) && filled($gmtDate) && is_string($gmtTime) && filled($gmtTime)) {
            $time = str_pad($gmtTime, 6, '0', STR_PAD_LEFT);
            $offset = preg_match('/^[+-]\d{2}:\d{2}$/', $gmtOffset) ? $gmtOffset : '+00:00';

            try {
                return CarbonImmutable::createFromFormat('Ymd His P', "{$gmtDate} {$time} {$offset}");
            } catch (\Throwable) {
                // Fall through to local date/time parsing below.
            }
        }

        return $this->parseUpsDateTime(
            $event['date'] ?? null,
            $event['time'] ?? null,
        );
    }

    private function parseUpsDateTime(mixed $date, mixed $time = null): ?CarbonImmutable
    {
        if (! is_string($date) || blank($date)) {
            return null;
        }

        $formattedTime = (is_string($time) && filled($time))
            ? str_pad($time, 6, '0', STR_PAD_LEFT)
            : '235959';

        try {
            return CarbonImmutable::createFromFormat('Ymd His', "{$date} {$formattedTime}");
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, TrackingEventData>  $events
     * @param  array<string, mixed>  $packageData
     */
    private function resolveDeliveredAt(array $events, array $packageData): ?CarbonImmutable
    {
        $deliveredEvent = collect($events)->first(fn (TrackingEventData $event): bool => str_contains(strtoupper($event->description), 'DELIVERED'));

        if ($deliveredEvent instanceof TrackingEventData) {
            return $deliveredEvent->timestamp;
        }

        $deliveredDate = collect($packageData['deliveryDate'] ?? [])
            ->first(fn ($date) => is_array($date) && (($date['type'] ?? null) === 'DEL'));

        $deliveredDateValue = is_array($deliveredDate) ? ($deliveredDate['date'] ?? null) : null;
        $deliveryTime = $packageData['deliveryTime'] ?? [];
        $deliveredTime = is_array($deliveryTime) && (($deliveryTime['type'] ?? null) === 'DEL')
            ? ($deliveryTime['endTime'] ?? null)
            : null;

        return $this->parseUpsDateTime($deliveredDateValue, $deliveredTime);
    }
}
