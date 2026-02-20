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
use App\Http\Integrations\Fedex\FedexConnector;
use App\Http\Integrations\Fedex\Requests\CancelShipment as CancelShipmentRequest;
use App\Http\Integrations\Fedex\Requests\CreateShipment;
use App\Http\Integrations\Fedex\Requests\Rates;
use App\Models\Package;
use App\Services\SettingsService;
use Illuminate\Support\Collection;
use Saloon\Http\Response;

class FedexAdapter implements CarrierAdapterInterface
{
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

    public function getCarrierName(): string
    {
        return 'FedEx';
    }

    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        // Check if we need to return mock rates for international sandbox testing
        $internationalCodes = array_intersect($serviceCodes, self::INTERNATIONAL_SERVICE_CODES);
        if ($this->isSandbox() && $this->isInternational($request) && ! empty($internationalCodes)) {
            logger()->debug('FedEx sandbox detected with international destination - returning mock rates', [
                'destination_country' => $request->destinationCountry,
                'service_codes' => $internationalCodes,
            ]);

            return $this->getMockInternationalRates($request, $internationalCodes);
        }

        $prepared = $this->prepareRateRequest($request, $serviceCodes);

        if (! $prepared) {
            return collect();
        }

        $connector = FedexConnector::getFedexConnector();
        $apiRequest = $this->buildRateApiRequest($request);
        $response = $connector->send($apiRequest);

        return $this->parseRateResponse($response, $request, $serviceCodes);
    }

    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
    {
        // Sandbox international mock rates don't need an API call
        $internationalCodes = array_intersect($serviceCodes, self::INTERNATIONAL_SERVICE_CODES);
        if ($this->isSandbox() && $this->isInternational($request) && ! empty($internationalCodes)) {
            return null;
        }

        if (empty($request->packages)) {
            return null;
        }

        $connector = FedexConnector::getFedexConnector();
        $apiRequest = $this->buildRateApiRequest($request);
        $pendingRequest = $connector->createPendingRequest($apiRequest);

        return new PreparedRateRequest(
            pendingRequest: $pendingRequest,
            carrierName: 'FedEx',
        );
    }

    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
    {
        if (! $response->successful()) {
            $errors = $response->json('errors', []);
            logger()->error('FedEx API Error', [
                'status' => $response->status(),
                'errors' => $errors,
                'body' => $response->json(),
            ]);

            return collect();
        }

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

            // transitDays can be a string like 'THREE_DAYS' or an object with minimumTransitTime
            $transitDays = $detail['commit']['transitDays'] ?? null;
            $transitTime = is_string($transitDays) ? $transitDays : ($transitDays['minimumTransitTime'] ?? null);

            // Prefer actual date (ISO format) over day-of-week string
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
     * Build the FedEx rate API request.
     */
    private function buildRateApiRequest(RateRequest $request): Rates
    {
        $package = $request->packages[0];

        $apiRequest = new Rates;
        $apiRequest->body()->set([
            'accountNumber' => [
                'value' => SettingsService::get('fedex.account_number', config('services.fedex.account_number')),
            ],
            'rateRequestControlParameters' => [
                'returnTransitTimes' => true,
            ],
            'requestedShipment' => [
                'shipper' => [
                    'address' => [
                        'postalCode' => $request->originPostalCode,
                        'countryCode' => 'US',
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
                'requestedPackageLineItems' => [
                    [
                        'weight' => [
                            'units' => 'LB',
                            'value' => $package->weight,
                        ],
                    ],
                ],
            ],
        ]);

        logger()->debug('FedEx API Request', [
            'body' => $apiRequest->body(),
        ]);

        return $apiRequest;
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
                'pickupType' => 'USE_SCHEDULED_PICKUP',
                'serviceType' => $request->selectedRate->metadata['serviceType'],
                'packagingType' => 'YOUR_PACKAGING',
                'shippingChargesPayment' => [
                    'paymentType' => 'SENDER',
                    'payor' => [
                        'responsibleParty' => [
                            'accountNumber' => [
                                'value' => SettingsService::get('fedex.account_number', config('services.fedex.account_number')),
                            ],
                        ],
                    ],
                ],
                'labelSpecification' => [
                    'labelFormatType' => 'COMMON2D',
                    'imageType' => 'PDF',
                    'labelStockType' => 'STOCK_4X6',
                ],
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

            // Add customs clearance detail for international shipments
            if ($request->toAddress->country !== 'US' && ! empty($request->customsItems)) {
                $requestedShipment['customsClearanceDetail'] = $this->buildCustomsClearanceDetail($request);
            }

            $apiRequest = new CreateShipment;
            $apiRequest->body()->set([
                'labelResponseOptions' => 'LABEL',
                'accountNumber' => [
                    'value' => SettingsService::get('fedex.account_number', config('services.fedex.account_number')),
                ],
                'requestedShipment' => $requestedShipment,
            ]);

            $response = $connector->send($apiRequest);
            $responseData = $response->json();

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
                    'value' => SettingsService::get('fedex.account_number', config('services.fedex.account_number')),
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

    public function isConfigured(): bool
    {
        return ! empty(SettingsService::get('fedex.api_key', config('services.fedex.api_key')))
            && ! empty(SettingsService::get('fedex.api_secret', config('services.fedex.api_secret')))
            && ! empty(SettingsService::get('fedex.account_number', config('services.fedex.account_number')));
    }

    public function supportsMultiPackage(): bool
    {
        return true;
    }

    public function supportsManifest(): bool
    {
        return false;
    }

    /**
     * Build FedEx contact/address structure from AddressData DTO.
     *
     * @return array<string, mixed>
     */
    private function buildContact(AddressData $address): array
    {
        $streetLines = array_filter([
            $address->streetAddress,
            $address->streetAddress2,
        ]);

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
                            'countryCode' => 'US',
                        ],
                        'accountNumber' => [
                            'value' => SettingsService::get('fedex.account_number', config('services.fedex.account_number')),
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
        return (bool) SettingsService::get('sandbox_mode', false);
    }

    /**
     * Check if the request is for an international destination.
     */
    private function isInternational(RateRequest $request): bool
    {
        return $request->destinationCountry !== 'US';
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
