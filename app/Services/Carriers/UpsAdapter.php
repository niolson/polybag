<?php

namespace App\Services\Carriers;

use App\Contracts\CarrierAdapterInterface;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Http\Integrations\Ups\Requests\CreateShipment;
use App\Http\Integrations\Ups\Requests\Rate;
use App\Http\Integrations\Ups\Requests\VoidShipment;
use App\Http\Integrations\Ups\UpsConnector;
use App\Models\Package;
use Illuminate\Support\Collection;

class UpsAdapter implements CarrierAdapterInterface
{
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

    public function getCarrierName(): string
    {
        return 'UPS';
    }

    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        try {
            $connector = UpsConnector::getAuthenticatedConnector();

            if (empty($request->packages)) {
                return collect();
            }

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
                                'PostalCode' => $request->originZip,
                                'CountryCode' => 'US',
                            ],
                        ],
                        'ShipTo' => [
                            'Address' => array_filter([
                                'City' => $request->destinationCity,
                                'StateProvinceCode' => $request->destinationState,
                                'PostalCode' => $request->destinationZip,
                                'CountryCode' => $request->destinationCountry,
                                'ResidentialAddressIndicator' => $request->residential ? '' : null,
                            ], fn ($v) => $v !== null),
                        ],
                        'ShipFrom' => [
                            'Address' => [
                                'PostalCode' => $request->originZip,
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
                    ],
                ],
            ]);

            logger()->debug('UPS Rate API Request', [
                'body' => $apiRequest->body(),
            ]);

            $response = $connector->send($apiRequest);

            if (! $response->successful()) {
                logger()->error('UPS Rate API Error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return collect();
            }

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
                $deliveryTime = $shipment['TimeInTransit']['ServiceSummary']['EstimatedArrival']['Arrival']['Time'] ?? null;

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
        } catch (\Exception $e) {
            logger()->error('UPS getRates error', ['error' => $e->getMessage()]);

            return collect();
        }
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
                    'ShipperNumber' => config('services.ups.account_number'),
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
                                'AccountNumber' => config('services.ups.account_number'),
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

            // Add international forms for non-US destinations
            if ($request->toAddress->country !== 'US' && ! empty($request->customsItems)) {
                $shipment['InternationalForms'] = $this->buildCustomsDetail($request);
            }

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
                            'Code' => 'PDF',
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

            $response = $connector->send($apiRequest);
            $responseData = $response->json();

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

            $totalCharge = (float) ($shipmentResults['ShipmentCharges']['TotalCharges']['MonetaryValue']
                ?? $request->selectedRate->price);

            return ShipResponse::success(
                trackingNumber: $trackingNumber,
                cost: $totalCharge,
                carrier: 'UPS',
                service: $request->selectedRate->serviceName,
                labelData: $labelData,
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
        return ! empty(config('services.ups.client_id'))
            && ! empty(config('services.ups.client_secret'))
            && ! empty(config('services.ups.account_number'));
    }

    public function supportsMultiPackage(): bool
    {
        return true;
    }

    /**
     * Build UPS address structure from AddressData DTO.
     *
     * @return array<string, mixed>
     */
    private function buildAddress(AddressData $address): array
    {
        $addressLines = array_values(array_filter([
            $address->streetAddress,
            $address->streetAddress2,
        ]));

        return array_filter([
            'AddressLine' => $addressLines,
            'City' => $address->city,
            'StateProvinceCode' => $address->state,
            'PostalCode' => $address->zip,
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

        foreach ($request->customsItems as $index => $item) {
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
}
