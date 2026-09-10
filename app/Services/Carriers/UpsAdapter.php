<?php

namespace App\Services\Carriers;

use App\Contracts\DirectCarrierAdapter;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\DataTransferObjects\Tracking\TrackingEventData;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\ServiceCapability;
use App\Enums\TrackingStatus;
use App\Exceptions\Carriers\CarrierRateFetchException;
use App\Http\Integrations\Ups\Requests\CreateShipment;
use App\Http\Integrations\Ups\Requests\Rate;
use App\Http\Integrations\Ups\Requests\TrackShipment;
use App\Http\Integrations\Ups\Requests\VoidShipment;
use App\Http\Integrations\Ups\UpsConnector;
use App\Models\CarrierAccount;
use App\Models\Package;
use App\Services\Carriers\Concerns\BuildsCustomerReferences;
use App\Services\Carriers\Concerns\ConsultsCarrierPolicyForOffers;
use App\Services\Carriers\Concerns\DecodesJsonResponses;
use App\Services\Carriers\Concerns\HasDefaultServiceCapabilities;
use App\Services\Carriers\Concerns\HasSaturdayDelivery;
use App\Services\Carriers\Concerns\ResolvesCarrierAccount;
use App\Services\Carriers\Concerns\ResolvesDeliveredAt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;

class UpsAdapter implements DirectCarrierAdapter
{
    use BuildsCustomerReferences;
    use ConsultsCarrierPolicyForOffers;
    use DecodesJsonResponses;
    use HasDefaultServiceCapabilities;
    use HasSaturdayDelivery;
    use ResolvesCarrierAccount;
    use ResolvesDeliveredAt;

    private function resolveConnector(?CarrierAccount $account): UpsConnector
    {
        return UpsConnector::getAuthenticatedConnector($account);
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
            // Section II excepted batteries need package marks only — no UPS API
            // declaration. Ground-only is additionally scoped to UPS Ground via
            // carrier-service scope rows.
            'lithium_battery_in_equipment' => ServiceCapability::Supported,
            'lithium_battery_ground_only' => ServiceCapability::Supported,
            // Standalone lithium (UN3480) requires UPS's full HazMat dangerous
            // goods declaration and a signed DG contract with UPS. Deliberately
            // out of scope: without that contract UPS rejects the shipment at
            // manifest, so claiming support here would fail at label purchase.
            default => ServiceCapability::NotImplemented,
        };
    }

    /**
     * UPS DeclaredValue accepts up to $50,000 per package (high-value shipments
     * beyond $5,000 may need account-level enablement — surfaced at rate time).
     */
    public function declaredValueCap(): ?float
    {
        return 50000.0;
    }

    /**
     * Build the UPS PackageServiceOptions payload for the wired special
     * services. DCISType uses the package-level code set (2 = signature,
     * 3 = adult signature) — the shipment-level set is numbered differently.
     *
     * @param  array<int, string>  $codes
     * @param  array<string, array<string, mixed>>  $config
     * @return array{options: array<string, mixed>, appliedCodes: array<int, string>}
     */
    private function buildPackageServiceOptions(array $codes, array $config): array
    {
        $options = [];
        $appliedCodes = [];

        if (in_array('adult_signature_required', $codes, true)) {
            $options['DeliveryConfirmation'] = ['DCISType' => '3'];
            $appliedCodes[] = 'adult_signature_required';
        } elseif (in_array('signature_required', $codes, true)) {
            $options['DeliveryConfirmation'] = ['DCISType' => '2'];
            $appliedCodes[] = 'signature_required';
        }

        $declaredAmount = (float) ($config['declared_value']['amount'] ?? 0);

        if (in_array('declared_value', $codes, true) && $declaredAmount > 0) {
            $options['DeclaredValue'] = [
                'CurrencyCode' => 'USD',
                'MonetaryValue' => number_format($declaredAmount, 2, '.', ''),
            ];
            $appliedCodes[] = 'declared_value';
        }

        return ['options' => $options, 'appliedCodes' => $appliedCodes];
    }

    /**
     * UPS accepts two reference numbers, each up to 35 characters. Which level
     * of the payload they belong on depends on the lane — see
     * acceptsPackageLevelReferences().
     *
     * @return array<string, mixed>
     */
    private function buildReferenceNumbers(ShipRequest $request): array
    {
        $references = $this->labelReferences($request, maxLength: 35, maxCount: 2);

        if ($references === []) {
            return [];
        }

        return [
            'ReferenceNumber' => array_map(fn (string $reference): array => [
                // TN = Transaction Reference Number, the generic bucket in the
                // UPS reference code list.
                'Code' => 'TN',
                'Value' => $reference,
            ], $references),
        ];
    }

    /**
     * Whether reference numbers belong on the package rather than the shipment.
     *
     * UPS splits this by lane, and each level is wrong for the other's lane:
     * package-level references are only permitted when both ends sit inside one
     * domestic area — the fifty states, or Puerto Rico — while shipment-level
     * references are accepted everywhere but are not printed on labels for
     * those same domestic lanes. Note that US↔PR is not domestic for this rule
     * even though both ends carry country code US, so the comparison has to be
     * zone against zone rather than country against country.
     */
    private function acceptsPackageLevelReferences(ShipRequest $request): bool
    {
        $origin = $request->fromAddress;

        if ($origin->country !== 'US' || ! $origin->sharesCustomsZoneWith($request->toAddress)) {
            return false;
        }

        if ($origin->isMilitary()) {
            return false;
        }

        return ! $origin->isUsTerritory()
            || strtoupper(trim((string) $origin->stateOrProvince)) === 'PR';
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

    /**
     * @return array<int|string, int>
     */
    protected function saturdayDeliveryDayMap(): array
    {
        return self::SATURDAY_DELIVERY_DAY_MAP;
    }

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

            $connector = $this->resolveConnector(
                $this->resolveAccount($request->locationId, $request->clientId)
            );
            $apiRequest = $this->buildRateApiRequest($this->adjustRequestForSaturday($request, $serviceCodes));
            $response = $connector->send($apiRequest);

            // Pass original $request so parseRateResponse knows Saturday was requested
            return $this->parseRateResponse($response, $request, $serviceCodes);
        } catch (\Exception $e) {
            throw new CarrierRateFetchException('UPS', $e);
        }
    }

    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
    {
        if (empty($request->packages)) {
            return null;
        }

        $connector = $this->resolveConnector(
            $this->resolveAccount($request->locationId, $request->clientId)
        );
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
            Log::channel('ups-validation')->error('UPS Rate API Error', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return collect();
        }

        Log::channel('ups-validation')->debug('RATE RESPONSE', [
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        $results = $this->extractRateDetails($response, $serviceCodes);

        // Mixed Saturday: initial request was sent without Saturday, now send
        // a follow-up with Saturday for eligible services and merge results
        if ($request->hasSpecialService('saturday_delivery') && $this->classifySaturdayEligibility($serviceCodes, $request) === 'mixed') {
            try {
                $connector = $this->resolveConnector(
                    $this->resolveAccount($request->locationId, $request->clientId)
                );
                $saturdayApiRequest = $this->buildRateApiRequest($request);
                $saturdayResponse = $connector->send($saturdayApiRequest);

                if ($saturdayResponse->successful()) {
                    $saturdayRates = $this->extractRateDetails($saturdayResponse, $serviceCodes);

                    if ($saturdayRates->isNotEmpty()) {
                        $saturdayServiceCodes = $saturdayRates->pluck('serviceCode')->unique()->all();
                        $results = $results->reject(
                            fn ($rate): bool => in_array($rate->serviceCode, $saturdayServiceCodes)
                        );
                        $results = $results->merge($saturdayRates);
                    }
                } else {
                    Log::channel('ups-validation')->warning('UPS Saturday delivery rate request failed', [
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
        $connector = $this->resolveConnector(
            $this->resolveAccount($package->location_id, $package->shipment?->client_id)
        );
        $trackRequest = new TrackShipment($package->tracking_number);
        $requestUri = rtrim($connector->resolveBaseUrl(), '/').$trackRequest->resolveEndpoint();

        try {

            Log::channel('ups-validation')->info('TRACK REQUEST', [
                'tracking_number' => $package->tracking_number,
                'uri' => $requestUri,
                'headers' => Arr::except($trackRequest->headers()->all(), ['Authorization']),
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
                ->filter(fn ($event): bool => is_array($event))
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
                'uri' => $requestUri,
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
            Log::channel('ups-validation')->error('UPS trackShipment error', [
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
            Log::channel('ups-validation')->warning('UPS Rate API returned invalid RatedShipment', [
                'body' => $response->json(),
            ]);

            return collect();
        }

        // Normalize to array of shipments (single result may not be wrapped)
        if (isset($ratedShipments['Service'])) {
            $ratedShipments = [$ratedShipments];
        }

        $returnedServiceCodes = array_map(fn ($s): mixed => $s['Service']['Code'] ?? 'unknown', $ratedShipments);
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
        $packageServiceOptions = $this->buildPackageServiceOptions(
            $request->specialServiceCodes,
            $request->specialServiceConfig,
        )['options'];

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
                        'Address' => $this->buildRateOriginAddress($request),
                    ],
                    'ShipTo' => [
                        'Address' => array_filter([
                            'City' => $request->destinationCity,
                            'StateProvinceCode' => $request->destinationStateOrProvince,
                            'PostalCode' => $request->destinationPostalCode,
                            'CountryCode' => $request->destinationCountry,
                            'ResidentialAddressIndicator' => $request->residential ? '' : null,
                        ], fn ($v): bool => $v !== null),
                    ],
                    'ShipFrom' => [
                        'Address' => $this->buildRateOriginAddress($request),
                    ],
                    ...$this->buildRateShipmentTotalWeight($request),
                    ...$this->buildRateInvoiceLineTotal($request),
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
                        ...($packageServiceOptions !== [] ? [
                            'PackageServiceOptions' => $packageServiceOptions,
                        ] : []),
                    ],
                    'DeliveryTimeInformation' => array_filter([
                        'PackageBillType' => '03',
                        'Pickup' => $request->shipDate ? [
                            'Date' => $request->shipDate->format('Ymd'),
                        ] : null,
                    ]),
                    ...($request->hasSpecialService('saturday_delivery') ? [
                        'ShipmentServiceOptions' => [
                            'SaturdayDeliveryIndicator' => '',
                        ],
                    ] : []),
                ],
            ],
        ]);

        Log::channel('ups-validation')->debug('RATE REQUEST', [
            'payload' => $apiRequest->body()->all(),
        ]);

        return $apiRequest;
    }

    public function createShipment(ShipRequest $request): ShipResponse
    {
        $account = $this->resolveAccount($request->locationId, $request->clientId);

        try {
            $connector = $this->resolveConnector($account);

            $serviceCode = $request->selectedRate->metadata['serviceCode'] ?? $request->selectedRate->serviceCode;

            $mapped = $this->buildPackageServiceOptions($request->specialServiceCodes, $request->specialServiceConfig);

            $references = $this->buildReferenceNumbers($request);
            $packageLevelReferences = $this->acceptsPackageLevelReferences($request) ? $references : [];
            $shipmentLevelReferences = $packageLevelReferences === [] ? $references : [];

            $shipment = [
                'Description' => 'Shipment',
                'Shipper' => [
                    'Name' => trim($request->fromAddress->company ?: $request->fromAddress->firstName.' '.$request->fromAddress->lastName),
                    'AttentionName' => $this->buildAttentionName($request->fromAddress),
                    'ShipperNumber' => $this->resolveAccountNumber($account),
                    ...$this->buildPhone($request->fromAddress),
                    'Address' => $this->buildAddress($request->fromAddress),
                ],
                'ShipTo' => [
                    'Name' => trim($request->toAddress->firstName.' '.$request->toAddress->lastName),
                    'AttentionName' => $this->buildAttentionName($request->toAddress),
                    ...$this->buildPhone($request->toAddress),
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
                                'AccountNumber' => $this->resolveAccountNumber($account),
                            ],
                        ],
                    ],
                ],
                'Service' => [
                    'Code' => $serviceCode,
                ],
                ...$shipmentLevelReferences,
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
                        ...$packageLevelReferences,
                        ...($mapped['options'] !== [] ? [
                            'PackageServiceOptions' => $mapped['options'],
                        ] : []),
                    ],
                ],
            ];

            // Add Saturday delivery if requested
            $saturdayApplied = $request->hasSpecialService('saturday_delivery');
            if ($saturdayApplied) {
                $shipment['ShipmentServiceOptions']['SaturdayDeliveryIndicator'] = '';
            }

            // Add international forms for non-US destinations. UPS defines
            // InternationalForms on ShipmentServiceOptions, not on Shipment —
            // sent a level higher it validates against the schema and is then
            // silently ignored, so no customs invoice is ever generated.
            if ($request->toAddress->country !== 'US' && ! empty($request->customsItems)) {
                $shipment['ShipmentServiceOptions']['InternationalForms'] = $this->buildCustomsDetail($request);
                $shipment['InvoiceLineTotal'] = $this->buildShipInvoiceLineTotal($request);
            }

            $response = $this->sendCreateShipment($connector, $shipment, $request);
            $responseData = $response->json();

            // If Saturday delivery was rejected, retry without it
            if ($saturdayApplied && ! $response->successful()) {
                $errorJson = json_encode($responseData);
                if (str_contains(strtolower($errorJson), 'saturday')) {
                    Log::channel('ups-validation')->info('UPS Saturday delivery rejected, retrying without', [
                        'body' => $responseData,
                    ]);
                    $saturdayApplied = false;
                    // Drop only Saturday. ShipmentServiceOptions may also carry
                    // the customs invoice, which the retry must not strip.
                    unset($shipment['ShipmentServiceOptions']['SaturdayDeliveryIndicator']);
                    if ($shipment['ShipmentServiceOptions'] === []) {
                        unset($shipment['ShipmentServiceOptions']);
                    }
                    $response = $this->sendCreateShipment($connector, $shipment, $request);
                    $responseData = $response->json();
                }
            }

            if (! $response->successful()) {
                $errorMessage = $responseData['response']['errors'][0]['message']
                    ?? $responseData['errors'][0]['message']
                    ?? 'UPS API error';
                Log::channel('ups-validation')->error('UPS createShipment API error', [
                    'status' => $response->status(),
                    'body' => $responseData,
                ]);

                return ShipResponse::failure($errorMessage);
            }

            $shipmentResults = $responseData['ShipmentResponse']['ShipmentResults'] ?? null;

            if (! $shipmentResults) {
                Log::channel('ups-validation')->error('UPS createShipment missing ShipmentResults', [
                    'body' => $responseData,
                ]);

                return ShipResponse::failure('UPS response missing shipment results');
            }

            $trackingNumber = $shipmentResults['ShipmentIdentificationNumber'] ?? null;

            if (empty($trackingNumber)) {
                Log::channel('ups-validation')->error('UPS createShipment missing tracking number', [
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
                Log::channel('ups-validation')->error('UPS createShipment missing label data', [
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

            // UPS returns the international forms it was asked for as their own
            // document, separately from the label and in their own format —
            // observed as PDF beside a GIF label. It is stored for the report
            // printer rather than the 4x6 label printer.
            $customsFormData = $shipmentResults['Form']['Image']['GraphicImage'] ?? null;

            $totalCharge = (float) ($shipmentResults['ShipmentCharges']['TotalCharges']['MonetaryValue']
                ?? $request->selectedRate->price);

            $isZpl = $request->labelFormat === 'zpl';

            return ShipResponse::success(
                trackingNumber: $trackingNumber,
                cost: $totalCharge,
                carrier: 'UPS',
                service: $request->selectedRate->serviceName,
                customsFormData: $customsFormData,
                labelData: $labelData,
                labelOrientation: $isZpl ? 'portrait' : 'landscape',
                labelFormat: $isZpl ? 'zpl' : 'image',
                labelDpi: $request->labelDpi,
                shipDate: $request->shipDate,
                appliedServices: [
                    ...($saturdayApplied ? ['saturday_delivery'] : []),
                    ...$mapped['appliedCodes'],
                ],
                carrierAccountId: $account?->id,
            );
        } catch (\Exception $e) {
            Log::channel('ups-validation')->error('UPS createShipment error', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ShipResponse::failure($e->getMessage());
        }
    }

    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
    {
        try {
            $connector = $this->resolveConnector(
                $this->resolveAccount($package->location_id, $package->shipment?->client_id)
            );

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
            logger()->error('UPS cancelShipment error', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'tracking_number' => $trackingNumber,
            ]);

            return CancelResponse::failure($e->getMessage());
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
     * Send a built shipment to UPS and return the raw response.
     *
     * The service lives in $shipment['Service'], set by the caller, so no
     * service code is passed separately.
     *
     * @param  array<string, mixed>  $shipment
     */
    private function sendCreateShipment(UpsConnector $connector, array $shipment, ShipRequest $request): Response
    {
        $apiRequest = new CreateShipment;
        $body = [
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
        ];

        $apiRequest->body()->set($body);

        Log::channel('ups-validation')->debug('LABEL REQUEST', [
            'payload' => $body,
        ]);

        $response = $connector->send($apiRequest);

        Log::channel('ups-validation')->debug('LABEL RESPONSE', [
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        return $response;
    }

    /**
     * The origin as sent on a rate request.
     *
     * Postal code and country alone are enough for UPS to rate a domestic lane,
     * but not to resolve an origin for an international one — the request comes
     * back as "Invalid Origin" (111538). City and state are sent whenever the
     * location has them.
     *
     * @return array<string, mixed>
     */
    private function buildRateOriginAddress(RateRequest $request): array
    {
        return array_filter([
            'City' => $request->originCity,
            'StateProvinceCode' => $request->originStateOrProvince,
            'PostalCode' => $request->originPostalCode,
            'CountryCode' => $request->originCountry,
        ], fn ($value): bool => filled($value));
    }

    /**
     * Whether a rate request leaves the country it ships from.
     *
     * Puerto Rico reaches us under either encoding depending on the import
     * source (country PR, or country US with state PR), so both count as
     * leaving the origin's country.
     */
    private function crossesBorder(RateRequest $request): bool
    {
        return $request->originCountry !== $request->destinationCountry
            || strtoupper(trim((string) $request->destinationStateOrProvince)) === 'PR';
    }

    /**
     * The shipment's total weight, which UPS requires to return time-in-transit
     * data for an international rate request — without it the quote comes back
     * "Invalid Weight" (111546) no matter what the package itself weighs.
     *
     * @return array<string, mixed>
     */
    private function buildRateShipmentTotalWeight(RateRequest $request): array
    {
        if (! $this->crossesBorder($request)) {
            return [];
        }

        $totalWeight = round(
            array_sum(array_map(fn (PackageData $package): float => $package->weight, $request->packages)),
            1,
        );

        if ($totalWeight <= 0) {
            return [];
        }

        return [
            'ShipmentTotalWeight' => [
                'UnitOfMeasurement' => [
                    'Code' => 'LBS',
                ],
                'Weight' => (string) $totalWeight,
            ],
        ];
    }

    /**
     * The declared value of what is being shipped, which UPS wants before it
     * will rate a forward international lane. Observed against a US→Japan quote,
     * which came back "Invalid Shipment Contents Value" (111549) without it;
     * UPS documents the same requirement for Puerto Rico and Canada.
     *
     * Puerto Rico reaches us under either encoding depending on the import
     * source (country PR, or country US with state PR), so both are treated as
     * leaving the origin's country.
     *
     * @return array<string, mixed>
     */
    private function buildRateInvoiceLineTotal(RateRequest $request): array
    {
        if (! $this->crossesBorder($request) || ! ($request->contentsValue > 0)) {
            return [];
        }

        return [
            'InvoiceLineTotal' => [
                'CurrencyCode' => 'USD',
                'MonetaryValue' => number_format($request->contentsValue, 2, '.', ''),
            ],
        ];
    }

    /**
     * UPS wants the contact number as digits in a container of its own, and
     * turns down an international label without one on the recipient
     * ("Missing or invalid ship to phone number", 120209). AddressData already
     * holds carrier-ready digits, so nothing is reformatted here.
     *
     * @return array<string, mixed>
     */
    private function buildPhone(AddressData $address): array
    {
        if (blank($address->phone)) {
            return [];
        }

        return [
            'Phone' => array_filter([
                'Number' => $address->phone,
                'Extension' => $address->phoneExtension,
            ], fn ($value): bool => filled($value)),
        ];
    }

    /**
     * The person UPS should ask for on delivery. Required on both ends of an
     * international shipment; the company name stands in when the address names
     * no individual.
     */
    private function buildAttentionName(AddressData $address): string
    {
        return trim($address->firstName.' '.$address->lastName) ?: (string) $address->company;
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
     * The declared value of the goods, which UPS requires on the shipment
     * itself whenever an Invoice form is attached — without it the label is
     * refused with 120502, "InvoiceLineTotal MonetaryValue must be greater than
     * 0". It is summed from the same customs items the invoice lists rather
     * than read off the shipment, so the two always agree.
     *
     * @return array<string, string>
     */
    private function buildShipInvoiceLineTotal(ShipRequest $request): array
    {
        $total = array_sum(array_map(
            fn (CustomsItem $item): float => round($item->unitValue * $item->quantity, 2),
            $request->customsItems,
        ));

        return [
            'CurrencyCode' => 'USD',
            'MonetaryValue' => number_format($total, 2, '.', ''),
        ];
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
            $product = [
                // UPS takes the description as up to three lines of 35 characters.
                'Description' => [mb_substr($item->description, 0, 35)],
                'Unit' => [
                    'Number' => (string) $item->quantity,
                    'UnitOfMeasurement' => [
                        'Code' => 'PCS',
                    ],
                    // The price of one, not the line. UPS prints this as "Unit
                    // Value" and multiplies it by Number for the line's total, so
                    // an extended total here declares the goods at quantity times
                    // their worth.
                    'Value' => (string) round($item->unitValue, 2),
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
            // A list of the forms requested, not a code/description pair. 01 is Invoice.
            'FormType' => ['01'],
            'InvoiceDate' => now()->format('Ymd'),
            'ReasonForExport' => 'SALE',
            'CurrencyCode' => 'USD',
            'Product' => $products,
            // The buyer the invoice is made out to. UPS refuses an Invoice form
            // without it — 9120800, "Missing contact information" — even though
            // its own schema leaves Contacts optional.
            'Contacts' => [
                'SoldTo' => [
                    'Name' => $this->buildAttentionName($request->toAddress),
                    'AttentionName' => $this->buildAttentionName($request->toAddress),
                    ...$this->buildPhone($request->toAddress),
                    'Address' => $this->buildAddress($request->toAddress),
                ],
            ],
        ];
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
            ->first(fn ($date): bool => is_array($date) && in_array(($date['type'] ?? null), ['SDD', 'RDD'], true));

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

    protected function isDeliveredEvent(TrackingEventData $event): bool
    {
        return str_contains(strtoupper($event->description), 'DELIVERED');
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function deliveredAtFallback(array $summary): ?CarbonImmutable
    {
        $deliveredDate = collect($summary['deliveryDate'] ?? [])
            ->first(fn ($date): bool => is_array($date) && (($date['type'] ?? null) === 'DEL'));

        $deliveredDateValue = is_array($deliveredDate) ? ($deliveredDate['date'] ?? null) : null;
        $deliveryTime = $summary['deliveryTime'] ?? [];
        $deliveredTime = is_array($deliveryTime) && (($deliveryTime['type'] ?? null) === 'DEL')
            ? ($deliveryTime['endTime'] ?? null)
            : null;

        return $this->parseUpsDateTime($deliveredDateValue, $deliveredTime);
    }
}
