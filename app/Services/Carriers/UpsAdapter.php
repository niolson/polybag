<?php

namespace App\Services\Carriers;

use App\Contracts\DirectCarrierAdapter;
use App\Contracts\RecoversUnresolvedPurchase;
use App\Contracts\SendsCustomsTerms;
use App\Contracts\UsesCarrierAccount;
use App\DataTransferObjects\Customs\DeclaredCustomsTerms;
use App\DataTransferObjects\Customs\RecipientTaxId;
use App\DataTransferObjects\Customs\ResolvedCustomsTerms;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\CustomsItem;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\DataTransferObjects\Tracking\TrackingEventData;
use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\CarrierPackaging;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\DutiesTerms;
use App\Enums\RecipientTaxIdType;
use App\Enums\ServiceCapability;
use App\Enums\TrackingStatus;
use App\Exceptions\Carriers\CarrierException;
use App\Exceptions\Carriers\CarrierRateFetchException;
use App\Exceptions\Carriers\UnclassifiablePackagingException;
use App\Exceptions\Carriers\UnreadablePurchaseResponseException;
use App\Http\Integrations\Ups\Requests\CreateShipment;
use App\Http\Integrations\Ups\Requests\LabelRecovery;
use App\Http\Integrations\Ups\Requests\Rate;
use App\Http\Integrations\Ups\Requests\TrackShipment;
use App\Http\Integrations\Ups\Requests\VoidShipment;
use App\Http\Integrations\Ups\UpsConnector;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Services\Carriers\Concerns\BuildsCustomerReferences;
use App\Services\Carriers\Concerns\ConsultsCarrierPolicyForOffers;
use App\Services\Carriers\Concerns\DecodesJsonResponses;
use App\Services\Carriers\Concerns\HasDefaultServiceCapabilities;
use App\Services\Carriers\Concerns\IdentifiesCatalogServices;
use App\Services\Carriers\Concerns\ResolvesCarrierAccount;
use App\Services\Carriers\Concerns\ResolvesDeliveredAt;
use App\Support\LabelText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\ServerException;
use Saloon\Exceptions\Request\Statuses\RequestTimeOutException;
use Saloon\Http\Response;

class UpsAdapter implements DirectCarrierAdapter, RecoversUnresolvedPurchase, SendsCustomsTerms, UsesCarrierAccount
{
    use BuildsCustomerReferences;
    use ConsultsCarrierPolicyForOffers;
    use DecodesJsonResponses;
    use HasDefaultServiceCapabilities;
    use IdentifiesCatalogServices;
    use ResolvesCarrierAccount;
    use ResolvesDeliveredAt;

    /**
     * `Shipment.ShipperType` / `Shipment.ConsigneeType` codes.
     */
    private const PARTY_TYPE_BUSINESS = '01';

    private const PARTY_TYPE_CONSUMER = '02';

    /**
     * Sent as `ProductIdentifierExemptIndicator` beside a product's EU product
     * identifiers. The published Shipping spec (UPS-API/api-documentation,
     * 2026-10-05) defines it as a JSON boolean, unlike every other value in
     * the body; the camelCase string the August 2026 guidance showed was
     * accepted by CIE but is not the published field
     * (`international-customs-terms/02`).
     */
    private const PRODUCT_IDENTIFIER_NOT_EXEMPT = false;

    private const PRODUCT_ID_MAX_LENGTH = 100;

    /**
     * `ShipmentCharge.Type`: transportation, and duties and taxes.
     */
    private const CHARGE_TRANSPORTATION = '01';

    private const CHARGE_DUTIES_AND_TAXES = '02';

    /**
     * The Vendor Collect ID type code each seller registration regime prints
     * under, verified on CIE against the returned invoices
     * (`international-customs-terms/02`). UPS has no current code for a UK VAT
     * number: `0358` is deprecated, and CIE accepts it and drops it from the
     * invoice without a word, so `0000` (the number with no label) is used.
     */
    private const VENDOR_COLLECT_ID_TYPE_CODES = [
        'ioss' => '0356',
        'voec' => '0357',
        'arn' => '1052',
        'uk_vat' => '0000',
    ];

    /**
     * `GlobalTaxInformation` role and ID types for the consignee's tax ID.
     */
    private const AGENT_ROLE_CONSIGNEE = '30';

    private const ID_NUMBER_PERSONAL_TAX = '0005';

    private const ID_NUMBER_COMPANY_TAX = '1002';

    /**
     * The values of the EEI form a shipper-filed ITN needs beside the ITN
     * itself, or CIE refuses it with 128261.
     */
    private const FORM_TYPE_INVOICE = '01';

    private const FORM_TYPE_EEI = '11';

    private const EEI_SHIPPER_FILED = '1';

    private const EEI_SHIPPER_FILED_ITN = 'A';

    private const EEI_NOT_IN_BOND = '70';

    private const EEI_POINT_OF_ORIGIN_STATE = 'S';

    /**
     * `ModeOfTransport` values from the Shipping schema. A UPS service that
     * crosses the US land border by road is exported by truck; every other
     * service is flown. Air is also the fallback for a service this table
     * does not know: all of UPS's worldwide and express services are air, and
     * a service that is neither is one the app does not sell abroad.
     */
    private const EEI_TRANSPORT_AIR = 'Air';

    private const EEI_TRANSPORT_TRUCK = 'Truck';

    /**
     * The services that go by road, by code: Ground (03), Standard (11), 3 Day
     * Select (12) and the Ground Saver family (92, 93, 95), all of which UPS
     * runs on its ground network. They only leave the US by road to Canada or
     * Mexico, so the lane decides below.
     *
     * @var list<string>
     */
    private const GROUND_NETWORK_SERVICES = ['03', '11', '12', '92', '93', '95'];

    private const LAND_BORDER_COUNTRIES = ['CA', 'MX'];

    private const ULTIMATE_CONSIGNEE_DIRECT_CONSUMER = 'D';

    private const ULTIMATE_CONSIGNEE_OTHER = 'O';

    private const EEI_PARTIES_NOT_RELATED = 'N';

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
     * The two Label Recovery errors that settle an unresolved purchase, both
     * observed in production on 2026-09-18 (`postage-source-split/18`): no
     * shipment exists under the reference and shipper number, or one did and
     * has since been voided. Either way nothing usable exists and the package
     * may be quoted again. Every other error leaves the question open.
     */
    private const RECOVERY_NOT_FOUND = '9801031';

    private const RECOVERY_VOIDED = '9801040';

    /**
     * How old an attempt may be for UPS's "not found" to count as nothing
     * bought. UPS publishes no window for Label Recovery, and the 2026-09-18
     * probe only showed it finding a shipment seconds old, so this is a guess
     * on the safe side, matching USPS's production look-back: older than this,
     * a person checks the UPS account instead (`postage-source-split/16`).
     * Unverified — raise it if a production case shows UPS finding older ones.
     */
    private const RECOVERY_TRUST_DAYS = 7;

    /**
     * UPS accepts two reference numbers, each up to 35 characters. Which level
     * of the payload they belong on depends on the lane — see
     * acceptsPackageLevelReferences().
     *
     * A purchase made from an offer spends the first slot on the offer's
     * `public_id`, which is what Label Recovery is asked by if the reply never
     * arrives (`recoverPurchase()`); the client's references fill what is
     * left, so a client printing two loses the second on the label. ADR-0002
     * decision 4's recovery property outranks the second reference for the
     * tenants who would otherwise pay for orphaned labels.
     *
     * @return array<string, mixed>
     */
    private function buildReferenceNumbers(ShipRequest $request): array
    {
        $recoveryKey = $request->offer?->public_id;
        $references = $this->labelReferences($request, maxLength: 35, maxCount: $recoveryKey === null ? 2 : 1);

        if ($recoveryKey !== null) {
            array_unshift($references, $recoveryKey);
        }

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
     * UPS's packaging code for the shipper's own packaging, the same on the
     * rate body (`PackagingType`) and the ship body (`Packaging`).
     */
    private const CUSTOMER_SUPPLIED_PACKAGE = '02';

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
        return Carrier::UPS;
    }

    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        try {
            $prepared = $this->prepareRateRequest($request, $serviceCodes);

            if (! $prepared) {
                return collect();
            }

            $connector = $this->resolveConnector(
                $this->ratingAccount($request)
            );
            $response = $connector->send($this->buildRateApiRequest($request));

            return $this->parseRateResponse($response, $request, $serviceCodes);
        } catch (\Exception $e) {
            throw new CarrierRateFetchException(Carrier::UPS, $e);
        }
    }

    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
    {
        if (empty($request->packages)) {
            return null;
        }

        $connector = $this->resolveConnector(
            $this->ratingAccount($request)
        );
        $pendingRequest = $connector->createPendingRequest($this->buildRateApiRequest($request));

        return new PreparedRateRequest(
            pendingRequest: $pendingRequest,
            carrierName: Carrier::UPS,
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

        return $this->withCatalogIdentity($this->extractRateDetails(
            $response,
            $serviceCodes,
            $this->packagingCodeFor($request->packages[0]->carrierPackaging),
            $request->hasSpecialService('saturday_delivery'),
            // The account the offer will record as having quoted this price.
            $this->ratingAccount($request)?->id,
        ));
    }

    public function supportsTracking(): bool
    {
        return true;
    }

    public function trackShipment(Package $package): TrackShipmentResponse
    {
        try {
            $account = $this->labelAccount($package);
        } catch (CarrierException $e) {
            return TrackShipmentResponse::failure($e->getMessage());
        }

        $connector = $this->resolveConnector($account);
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
     * Extract rate details from a successful UPS rate response, each stamped
     * with the packaging code the request that produced it sent — the one fact
     * {@see classifyPackaging()} reads.
     *
     * A shop response lists a service twice when it can reach Saturday from the
     * ship date: the weekday row and a Saturday row, told apart only by
     * `TimeInTransit.ServiceSummary.SaturdayDelivery`. Without Saturday requested
     * the Saturday rows go, or the Ship page shows the same service at two
     * prices with nothing to tell them apart. With it requested UPS already
     * returns only the Saturday rows (the indicator is a filter, not an error),
     * so anything else is dropped and each kept rate is tagged, which is what
     * {@see CreateShipment()} reads to send the indicator the quote was priced with.
     *
     * @param  array<int, string>  $serviceCodes
     * @param  string  $packagingCode  A UPS packaging code, {@see packagingCodeFor()}
     * @param  bool  $saturdayRequested  Whether the request that produced this response carried `SaturdayDeliveryIndicator`
     */
    private function extractRateDetails(Response $response, array $serviceCodes, string $packagingCode, bool $saturdayRequested, ?int $carrierAccountId = null): Collection
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

            $saturdayRow = ($shipment['TimeInTransit']['ServiceSummary']['SaturdayDelivery'] ?? '0') === '1';

            if ($saturdayRow !== $saturdayRequested) {
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

            $metadata = [
                'serviceCode' => $serviceCode,
                'packagingCode' => $packagingCode,
                ...($saturdayRow ? ['saturday_delivery' => true] : []),
            ];

            $results->push(new RateResponse(
                carrier: Carrier::UPS,
                serviceCode: $serviceCode,
                serviceName: $serviceName,
                price: $totalCharges,
                deliveryDate: $deliveryDate,
                transitTime: $transitTime,
                metadata: $metadata,
                packagingRequirement: $this->classifyPackaging($metadata),
                carrierAccountId: $carrierAccountId,
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
                        ...$this->buildRateShipperNumber($request),
                        'Address' => $this->buildRateOriginAddress($request),
                    ],
                    ...$this->buildRatePaymentDetails($request),
                    'ShipTo' => [
                        'Address' => $this->buildRateDestinationAddress($request),
                    ],
                    'ShipFrom' => [
                        'Address' => $this->buildRateOriginAddress($request),
                    ],
                    ...$this->buildRateShipmentTotalWeight($request),
                    ...$this->buildRateInvoiceLineTotal($request),
                    'Package' => [
                        'PackagingType' => $this->buildPackaging($package->carrierPackaging),
                        'PackageWeight' => [
                            'UnitOfMeasurement' => [
                                'Code' => 'LBS',
                            ],
                            'Weight' => (string) $package->weight,
                        ],
                        ...($package->carrierPackaging === CarrierPackaging::UpsLetter ? [] : [
                            'Dimensions' => $this->buildDimensions($package),
                        ]),
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
        $response = null;

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
                    'Address' => $this->buildAddress($request->toAddress, includeResidentialClassification: true),
                ],
                'ShipFrom' => [
                    'Name' => trim($request->fromAddress->company ?: $request->fromAddress->firstName.' '.$request->fromAddress->lastName),
                    'Address' => $this->buildAddress($request->fromAddress),
                    ...$this->buildVendorInfo($request),
                    ...$this->buildExporterTaxId($request),
                ],
                // UPS decides from these two whether a shipment falls under
                // rules that turn on who is buying, the EU product identifiers
                // among them. Sent on every lane so UPS's own default never
                // decides: the shipper is always a warehouse, and a consignee
                // with no company name is the only consumer signal we have.
                'ShipperType' => self::PARTY_TYPE_BUSINESS,
                'ConsigneeType' => filled($request->toAddress->company) ? self::PARTY_TYPE_BUSINESS : self::PARTY_TYPE_CONSUMER,
                'PaymentInformation' => [
                    'ShipmentCharge' => $this->buildShipmentCharges($request->customsTerms, $this->resolveAccountNumber($account)),
                ],
                'Service' => [
                    'Code' => $serviceCode,
                ],
                ...$shipmentLevelReferences,
                'Package' => [
                    [
                        'Packaging' => $this->buildPackaging($request->packageData->carrierPackaging),
                        'PackageWeight' => [
                            'UnitOfMeasurement' => [
                                'Code' => 'LBS',
                            ],
                            'Weight' => (string) $request->packageData->weight,
                        ],
                        // UPS documents Dimensions as not applicable to a
                        // Letter, whose size is UPS's own; a quoted Letter must
                        // not fail at the label over a field it never needed.
                        ...($request->packageData->carrierPackaging === CarrierPackaging::UpsLetter ? [] : [
                            'Dimensions' => $this->buildDimensions($request->packageData),
                        ]),
                        ...$packageLevelReferences,
                        ...($mapped['options'] !== [] ? [
                            'PackageServiceOptions' => $mapped['options'],
                        ] : []),
                    ],
                ],
            ];

            if (($globalTaxInformation = $this->buildGlobalTaxInformation($request)) !== null) {
                $shipment['GlobalTaxInformation'] = $globalTaxInformation;
            }

            // Saturday delivery follows the quote the operator chose, not the
            // request flag: extractRateDetails() tags a rate only when UPS
            // priced it for Saturday, so the label matches what was quoted.
            $saturdayApplied = (bool) ($request->selectedRate->metadata['saturday_delivery'] ?? false);
            if ($saturdayApplied) {
                $shipment['ShipmentServiceOptions']['SaturdayDeliveryIndicator'] = '';
            }

            // Add international forms wherever the lane crosses a customs
            // zone — asked of the address pair, so a Canadian account shipping
            // into the US declares and one shipping within Canada does not,
            // and so the workflow's weight reconciliation and this branch agree
            // on which packages carry a declaration. UPS defines
            // InternationalForms on ShipmentServiceOptions, not on Shipment —
            // sent a level higher it validates against the schema and is then
            // silently ignored, so no customs invoice is ever generated.
            if ($this->sendsInternationalForms($request)) {
                $shipment['ShipmentServiceOptions']['InternationalForms'] = $this->buildCustomsDetail($request);
                $shipment['InvoiceLineTotal'] = $this->buildShipInvoiceLineTotal($request);
            }

            // A UPS refusal never comes back as a failed response: the
            // connector retries and throws, so the RequestException catch
            // below is the one place an error body is read. Anything past
            // this line is a 2xx — UPS created the shipment and charged for
            // it — so nothing after it may come back as a decline.
            $response = $this->sendCreateShipment($connector, $shipment, $request);
            $responseData = json_decode($response->body(), associative: true);

            if (! is_array($responseData)) {
                $this->unreadablePurchase($response, $request, 'UPS response was not JSON');
            }

            $shipmentResults = $responseData['ShipmentResponse']['ShipmentResults'] ?? null;

            if (! is_array($shipmentResults) || $shipmentResults === []) {
                $this->unreadablePurchase($response, $request, 'UPS response missing shipment results');
            }

            $trackingNumber = $shipmentResults['ShipmentIdentificationNumber'] ?? null;

            if (! is_scalar($trackingNumber) || (string) $trackingNumber === '') {
                $this->unreadablePurchase($response, $request, 'UPS response missing tracking number');
            }

            $trackingNumber = (string) $trackingNumber;

            // Package results may be a single object or array
            $packageResults = $shipmentResults['PackageResults'] ?? [];
            if (isset($packageResults['TrackingNumber'])) {
                $packageResults = [$packageResults];
            }

            $labelData = $packageResults[0]['ShippingLabel']['GraphicImage'] ?? null;

            if (empty($labelData)) {
                $this->unreadablePurchase($response, $request, 'UPS response missing label data', $trackingNumber);
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
                carrier: Carrier::UPS,
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
        } catch (FatalRequestException|RequestTimeOutException|ServerException $e) {
            // No answer is not a refusal, and neither is a 5xx — UPS may have
            // created the shipment before failing. The exception leaves the
            // offer unresolved and recoverPurchase() asks Label Recovery
            // before anything else is bought — ADR-0002 decision 4's fifth
            // property.
            Log::channel('ups-validation')->warning('UPS createShipment got no answer; the offer stays unresolved', [
                'exception' => $e::class,
                'error' => $this->scrubCustomsIds($e->getMessage(), $request),
                'offer' => $request->offer?->public_id,
            ]);

            throw $e;
        } catch (UnreadablePurchaseResponseException $e) {
            // Accepted and charged — see unreadablePurchase().
            throw $e;
        } catch (RequestException $e) {
            $rawResponse = $this->scrubCustomsIds($this->decodeJsonSafely($e->getResponse()), $request);

            Log::channel('ups-validation')->error('UPS createShipment API error', [
                'status' => $e->getResponse()->status(),
                'body' => $rawResponse,
            ]);

            return ShipResponse::failure($this->scrubCustomsIds(
                data_get($rawResponse, 'response.errors.0.message')
                    ?? data_get($rawResponse, 'errors.0.message')
                    ?? $e->getMessage(),
                $request,
            ));
        } catch (\Throwable $e) {
            if ($response !== null) {
                // Anything that breaks after the 2xx is still an accepted
                // purchase — a TypeError from a label image of the wrong
                // shape included.
                $this->unreadablePurchase($response, $request, $e->getMessage(), previous: $e);
            }

            if (! $e instanceof \Exception) {
                throw $e;
            }

            Log::channel('ups-validation')->error('UPS createShipment error', [
                'exception' => $e::class,
                'error' => $this->scrubCustomsIds($e->getMessage(), $request),
                'trace' => $e->getTraceAsString(),
            ]);

            return ShipResponse::failure($this->scrubCustomsIds($e->getMessage(), $request));
        }
    }

    /**
     * Refuse to call a 2xx purchase reply a decline.
     *
     * UPS answering 2xx means the shipment exists and is billed, so a reply
     * with no shipment results, tracking number or label image is an unknown
     * outcome. {@see UnreadablePurchaseResponseException} leaves the offer
     * unresolved, and {@see recoverPurchase()} asks Label Recovery for the
     * same shipment on the next attempt. The raw body is logged first because
     * it is the only copy of what UPS said — `project-review/11`.
     *
     * @throws UnreadablePurchaseResponseException
     */
    private function unreadablePurchase(
        Response $response,
        ShipRequest $request,
        string $reason,
        ?string $trackingNumber = null,
        ?\Throwable $previous = null,
    ): never {
        Log::channel('ups-validation')->error('UPS createShipment accepted the purchase but its reply could not be read; the offer stays unresolved', [
            'reason' => $this->scrubCustomsIds($reason, $request),
            'status' => $response->status(),
            'offer' => $request->offer?->public_id,
            'tracking_number' => $trackingNumber,
            'body' => $this->scrubCustomsIds($response->body(), $request),
        ]);

        throw new UnreadablePurchaseResponseException(Carrier::UPS, $this->scrubCustomsIds($reason, $request), $trackingNumber, $previous);
    }

    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
    {
        try {
            // The account that bought the label — see labelAccount().
            $connector = $this->resolveConnector($this->labelAccount($package));

            $apiRequest = new VoidShipment($trackingNumber);

            $response = $connector->send($apiRequest);

            if ($response->successful()) {
                return $this->readVoidReply($response, $trackingNumber);
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

    /**
     * A 2xx is not UPS's answer: `SummaryResult.Status.Code` is, and only `1`
     * means voided. A reply without a code cannot be read.
     */
    private function readVoidReply(Response $response, string $trackingNumber): CancelResponse
    {
        $body = $this->decodeJsonSafely($response);
        $code = data_get($body, 'VoidShipmentResponse.SummaryResult.Status.Code');
        $description = data_get($body, 'VoidShipmentResponse.SummaryResult.Status.Description');
        $description = is_string($description) && $description !== '' ? $description : null;

        if ((is_string($code) || is_int($code)) && (string) $code === '1') {
            return CancelResponse::success($description ?? 'UPS shipment voided.');
        }

        if (is_string($code) || is_int($code)) {
            return CancelResponse::failure('UPS did not void the shipment: '.($description ?? 'status code '.$code));
        }

        Log::channel('ups-validation')->error('UPS cancelShipment reply could not be read', [
            'status' => $response->status(),
            'tracking_number' => $trackingNumber,
            'body' => $response->body(),
        ]);

        return CancelResponse::unreadable('UPS');
    }

    public function supportsMultiPackage(): bool
    {
        return true;
    }

    public function supportsCarrierManifest(): bool
    {
        return false;
    }

    public function packagingRequirementFor(RateResponse $rate): PackagingRequirement
    {
        return $this->classifyPackaging($rate->metadata);
    }

    /**
     * UPS returns the invoice apart from the label: `ShipmentResults.Form.Image`,
     * a PDF of three Letter pages, whenever {@see CreateShipment()} sends
     * `InternationalForms` — which it does on exactly this condition. Read
     * into `customsFormData` and printed to the report printer, so a
     * workstation without one cannot print what UPS hands back.
     */
    public function customsDocumentDelivery(AddressData $from, AddressData $to, ?RateResponse $rate = null): CustomsDocumentDelivery
    {
        return $from->sharesCustomsZoneWith($to)
            ? CustomsDocumentDelivery::None
            : CustomsDocumentDelivery::Separate;
    }

    /**
     * Which packaging a UPS rate is valid in — ADR-0005 decision 3: the
     * adapter stamps the packaging it sent. UPS puts packaging on the request,
     * so every rate carries the `PackagingType` code its request named under
     * `packagingCode`: a UPS packaging code is `exactly(…)` the packaging it
     * stands for, and `02` is the shipper's own.
     *
     * A rate quoted before the code was stamped has no key and was quoted as
     * `02`, so it is the shipper's packaging. A code this adapter never sends —
     * which can only arrive restated by a browser — is refused rather than
     * defaulted, the invariant `05` set for USPS.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws UnclassifiablePackagingException when the code is not one this adapter maps a packaging to
     */
    private function classifyPackaging(array $metadata): PackagingRequirement
    {
        $sent = $metadata['packagingCode'] ?? self::CUSTOMER_SUPPLIED_PACKAGE;

        if ($sent === self::CUSTOMER_SUPPLIED_PACKAGE) {
            return PackagingRequirement::shipperPackaging();
        }

        $packaging = array_find(
            CarrierPackaging::cases(),
            fn (CarrierPackaging $candidate): bool => $this->packagingCodeFor($candidate) === $sent,
        );

        if ($packaging === null) {
            throw new UnclassifiablePackagingException(Carrier::UPS, "UPS packaging code {$sent} is not one PolyBag can place in a packaging.");
        }

        return PackagingRequirement::exactly($packaging);
    }

    /**
     * The `PackagingType` / `Packaging` container both bodies send. The
     * description is optional and unvalidated; it is the name the vendored
     * specs give each code.
     *
     * @return array{Code: string, Description: string}
     */
    private function buildPackaging(?CarrierPackaging $packaging): array
    {
        $code = $this->packagingCodeFor($packaging);

        return [
            'Code' => $code,
            'Description' => match ($code) {
                '01' => 'UPS Letter',
                '03' => 'Tube',
                '04' => 'PAK',
                '21' => 'UPS Express Box',
                '2a' => 'Small Express Box',
                '2b' => 'Medium Express Box',
                '2c' => 'Large Express Box',
                default => 'Customer Supplied Package',
            },
        ];
    }

    /**
     * @return array{UnitOfMeasurement: array{Code: string, Description: string}, Length: string, Width: string, Height: string}
     */
    private function buildDimensions(PackageData $package): array
    {
        $dimensions = $package->dimensionsInWholeInches();

        return [
            'UnitOfMeasurement' => ['Code' => 'IN', 'Description' => 'Inches'],
            'Length' => (string) $dimensions['length'],
            'Width' => (string) $dimensions['width'],
            'Height' => (string) $dimensions['height'],
        ];
    }

    /**
     * The UPS packaging code for a Package's carrier packaging — the one place
     * `CarrierPackaging` meets UPS's wire enum, used by the rate body, the ship
     * body and the classifier's reverse lookup. Codes per the vendored
     * Rating and Shipping specs; UPS's 10 kg and 25 kg boxes (`25` / `24`) are
     * enum additions when someone stocks them.
     *
     * Another carrier's packaging — a USPS flat-rate box — is `02`: UPS rates
     * the parcel as customer packaging, every rate that comes back is stamped
     * `shipperPackaging()`, and the shared filter drops all of them for a
     * Package that says it is in USPS packaging. That is the right outcome —
     * UPS cannot carry a USPS flat-rate box at a USPS flat rate — and it needs
     * no special case here.
     */
    private function packagingCodeFor(?CarrierPackaging $packaging): string
    {
        return match ($packaging) {
            CarrierPackaging::UpsLetter => '01',
            CarrierPackaging::UpsTube => '03',
            CarrierPackaging::UpsPak => '04',
            CarrierPackaging::UpsExpressBox => '21',
            CarrierPackaging::UpsExpressBoxSmall => '2a',
            CarrierPackaging::UpsExpressBoxMedium => '2b',
            CarrierPackaging::UpsExpressBoxLarge => '2c',
            null,
            CarrierPackaging::UspsFlatRateEnvelope,
            CarrierPackaging::UspsLegalFlatRateEnvelope,
            CarrierPackaging::UspsPaddedFlatRateEnvelope,
            CarrierPackaging::UspsSmallFlatRateBox,
            CarrierPackaging::UspsMediumFlatRateBox,
            CarrierPackaging::UspsLargeFlatRateBox,
            CarrierPackaging::UspsExpressFlatRateEnvelope,
            CarrierPackaging::UspsExpressLegalFlatRateEnvelope,
            CarrierPackaging::UspsExpressPaddedFlatRateEnvelope,
            CarrierPackaging::FedexEnvelope,
            CarrierPackaging::FedexPak,
            CarrierPackaging::FedexTube,
            CarrierPackaging::FedexBox,
            CarrierPackaging::FedexExtraSmallBox,
            CarrierPackaging::FedexSmallBox,
            CarrierPackaging::FedexMediumBox,
            CarrierPackaging::FedexLargeBox,
            CarrierPackaging::FedexExtraLargeBox,
            CarrierPackaging::Fedex10kgBox,
            CarrierPackaging::Fedex25kgBox => self::CUSTOMER_SUPPLIED_PACKAGE,
        };
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

        // Decoded safely: a body that is not JSON must reach throw() below,
        // or the adapter's own classification, rather than fail in a log line.
        Log::channel('ups-validation')->debug('LABEL RESPONSE', [
            'status' => $response->status(),
            'body' => $this->scrubCustomsIds($this->decodeJsonSafely($response), $request),
        ]);

        // The request is sent once (see CreateShipment::$tries), which also
        // means a refusal comes back as a response rather than thrown; throw
        // it so the caller's RequestException catch stays the one place an
        // error body is read.
        $response->throw();

        return $response;
    }

    /**
     * Ask UPS whether a spent offer created a shipment, by the reference it
     * was sent under.
     *
     * Label Recovery is the question: side-effect free, and a 200 is the
     * label already created, so the package ships on it. `9801031` is UPS
     * being certain nothing exists under the reference and shipper number,
     * and `9801040` that what did has been voided — both settle the offer as
     * declined. Anything else, including a transport error, leaves it
     * unresolved.
     *
     * Label Recovery returns no charges, so the cost is the offer's price.
     * It does return the international forms UPS generated with the label,
     * which a cross-border package needs as much as the label itself: a
     * recovery that finds the label but not the form it should have is left
     * unresolved rather than shipped without a customs document.
     *
     * Asked on the account the offer was bought on, never the one scopes
     * prefer now: the lookup is by reference *and* shipper number, so another
     * account's "not found" says nothing about the shipment — see
     * {@see purchasingAccountChanged()}.
     */
    private function recoveryLookupCovers(ShippingOffer $offer): bool
    {
        return $offer->consumed_at !== null
            && $offer->consumed_at->isAfter(now()->subDays(self::RECOVERY_TRUST_DAYS - 1));
    }

    public function recoverPurchase(ShipRequest $request): ?ShipResponse
    {
        $offer = $request->offer;
        $recoveryKey = $offer?->public_id;

        if ($offer === null || $recoveryKey === null) {
            return null;
        }

        if ($this->purchasingAccountChanged($offer)) {
            Log::channel('ups-validation')->warning('Cannot ask UPS about a purchase: the account it was bought on is gone or bills someone else now', [
                'offer' => $recoveryKey,
                'carrier_account_id' => $offer->carrier_account_id,
            ]);

            return null;
        }

        $account = $this->purchasingAccount($offer, $request->locationId, $request->clientId);
        $shipperNumber = $this->resolveAccountNumber($account);

        if ($shipperNumber === null) {
            return null;
        }

        try {
            $connector = $this->resolveConnector($account);
            $response = $connector->send(new LabelRecovery(
                $recoveryKey,
                $shipperNumber,
                $request->labelFormat === 'zpl' ? 'ZPL' : 'GIF',
            ));
        } catch (RequestException $e) {
            $response = $e->getResponse();
        } catch (\Exception $e) {
            Log::channel('ups-validation')->warning('Could not ask UPS what became of a purchase', [
                'offer' => $recoveryKey,
                'exception' => $e::class,
                'error' => $this->scrubCustomsIds($e->getMessage(), $request),
            ]);

            return null;
        }

        $body = $this->scrubCustomsIds($this->decodeJsonSafely($response), $request);

        if (! $response->successful()) {
            $errors = data_get($body, 'response.errors', data_get($body, 'errors', []));
            $codes = array_map(fn ($error): string => (string) ($error['code'] ?? ''), is_array($errors) ? $errors : []);

            Log::channel('ups-validation')->info('UPS Label Recovery by reference did not return a label', [
                'offer' => $recoveryKey,
                'status' => $response->status(),
                'codes' => $codes,
            ]);

            // Past the window UPS is trusted to look back, "not found" could
            // be a shipment it no longer finds: unknown, for a person to check
            // (`postage-source-split/16`).
            if (in_array(self::RECOVERY_NOT_FOUND, $codes, true) && ! $this->recoveryLookupCovers($offer)) {
                Log::channel('ups-validation')->warning('UPS Label Recovery "not found" is not trusted for a purchase this old', [
                    'offer' => $recoveryKey,
                    'attempted_at' => $offer->consumed_at?->toIso8601String(),
                ]);

                return null;
            }

            if (array_intersect($codes, [self::RECOVERY_NOT_FOUND, self::RECOVERY_VOIDED]) !== []) {
                $message = data_get($errors, '0.message') ?? 'UPS has no shipment for the earlier purchase attempt.';

                return ShipResponse::failure(in_array(self::RECOVERY_VOIDED, $codes, true)
                    ? 'The shipment from the earlier purchase attempt has since been voided at UPS.'
                    : 'UPS has no shipment for the earlier purchase attempt; nothing was bought. ('.$message.')');
            }

            return null;
        }

        $results = data_get($body, 'LabelRecoveryResponse.LabelResults');

        if (isset($results['TrackingNumber'])) {
            $results = [$results];
        }

        $trackingNumber = data_get($body, 'LabelRecoveryResponse.ShipmentIdentificationNumber')
            ?? data_get($results, '0.TrackingNumber');
        $labelData = data_get($results, '0.LabelImage.GraphicImage');

        if (empty($trackingNumber) || empty($labelData)) {
            Log::channel('ups-validation')->error('UPS Label Recovery answered without a tracking number or label', [
                'offer' => $recoveryKey,
                'body' => $body,
            ]);

            return null;
        }

        // The same document the purchase path stores from ShipmentResults.Form.
        $customsFormData = data_get($body, 'LabelRecoveryResponse.Form.Image.GraphicImage');
        $needsCustomsForm = ! $request->fromAddress->sharesCustomsZoneWith($request->toAddress) && $request->customsItems !== [];

        if ($needsCustomsForm && empty($customsFormData)) {
            Log::channel('ups-validation')->error('UPS Label Recovery found the label but not the international forms the lane needs', [
                'offer' => $recoveryKey,
                'tracking_number' => $trackingNumber,
            ]);

            return null;
        }

        $isZpl = $request->labelFormat === 'zpl';

        if ($isZpl && $request->labelDpi === 300) {
            $decoded = base64_decode($labelData);
            $decoded = preg_replace('/\^XA/', '^XA^JMA', $decoded, 1);
            $labelData = base64_encode($decoded);
        }

        Log::channel('ups-validation')->info('Recovered a UPS label by reference', [
            'offer' => $recoveryKey,
            'tracking_number' => $trackingNumber,
        ]);

        return ShipResponse::success(
            trackingNumber: $trackingNumber,
            cost: (float) $request->selectedRate->price,
            carrier: Carrier::UPS,
            service: $request->selectedRate->serviceName,
            labelData: $labelData,
            labelOrientation: $isZpl ? 'portrait' : 'landscape',
            labelFormat: $isZpl ? 'zpl' : 'image',
            labelDpi: $request->labelDpi,
            shipDate: $request->shipDate,
            carrierAccountId: $account?->id,
            customsFormData: is_string($customsFormData) && $customsFormData !== '' ? $customsFormData : null,
        );
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
     * @return array<string, mixed>
     */
    private function buildRateDestinationAddress(RateRequest $request): array
    {
        $addressLines = $this->buildAddressLines(
            $request->destinationStreetAddress,
            $request->destinationStreetAddress2,
        );

        return array_filter([
            'AddressLine' => $addressLines === [] ? null : $addressLines,
            'City' => $request->destinationCity === null ? null : LabelText::asciiDigits($request->destinationCity),
            'StateProvinceCode' => $request->destinationStateOrProvince,
            'PostalCode' => $request->destinationPostalCode,
            'CountryCode' => $request->destinationCountry,
            'ResidentialAddressIndicator' => $request->residential ? '' : null,
        ], fn (mixed $value): bool => $value !== null);
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

    private function buildAddress(AddressData $address, bool $includeResidentialClassification = false): array
    {
        $addressLines = $this->buildAddressLines($address->streetAddress, $address->streetAddress2);

        return [
            ...array_filter([
                'AddressLine' => $addressLines,
                'City' => LabelText::asciiDigits($address->city),
                'StateProvinceCode' => $address->stateOrProvince,
                'PostalCode' => $address->postalCode,
                'CountryCode' => $address->country,
            ], fn (mixed $value): bool => filled($value)),
            ...($includeResidentialClassification && $address->isResidential() ? [
                'ResidentialAddressIndicator' => '',
            ] : []),
        ];
    }

    /**
     * Street lines with their digits made ASCII. UPS transliterates letters
     * itself — `ę` printed as `E` in ZPL, and as `Ę` on a GIF — but not
     * Persian or Devanagari digits, which printed as empty boxes on the label
     * and `*` on the commercial invoice (`label-address-characters`): a lost
     * house number. Shared by the label and the rate request.
     *
     * @return list<string>
     */
    private function buildAddressLines(?string $streetAddress, ?string $streetAddress2): array
    {
        return array_values(array_map(
            LabelText::asciiDigits(...),
            array_filter(
                [$streetAddress, $streetAddress2],
                fn (?string $value): bool => filled($value),
            ),
        ));
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
        $declaresEuProductIdentifiers = $request->toAddress->isInEuropeanUnion();

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

            if ($declaresEuProductIdentifiers && ($identifiers = $this->euProductIdentifiers($item)) !== null) {
                $product['ProductIdentifierExemptIndicator'] = self::PRODUCT_IDENTIFIER_NOT_EXEMPT;
                $product['ProductIdentifier'] = $identifiers;
            }

            $products[] = $product;
        }

        $filesEei = $this->filesEei($request);

        return [
            // A list of the forms requested, not a code/description pair. 01 is
            // Invoice; 11 is EEI, requested only when an ITN is declared.
            'FormType' => $filesEei ? [self::FORM_TYPE_INVOICE, self::FORM_TYPE_EEI] : [self::FORM_TYPE_INVOICE],
            'InvoiceDate' => now()->format('Ymd'),
            ...$this->buildTermsOfShipment($request->customsTerms),
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
                ...($filesEei ? ['UltimateConsignee' => $this->buildUltimateConsignee($request)] : []),
            ],
            ...($filesEei ? $this->buildEeiForm($request) : []),
        ];
    }

    /**
     * The shipment charges: transportation, and for DDP the duties and taxes
     * on the same account, which makes the rate carry UPS's Duty and Tax
     * Forwarding surcharge (itemized charge 378) and the label bill the
     * shipper at the door. DDU sends transportation only.
     *
     * @return list<array{Type: string, BillShipper: array{AccountNumber: string|null}}>
     */
    private function buildShipmentCharges(?ResolvedCustomsTerms $terms, ?string $accountNumber): array
    {
        $types = [self::CHARGE_TRANSPORTATION];

        if ($terms?->dutiesTerms === DutiesTerms::Ddp) {
            $types[] = self::CHARGE_DUTIES_AND_TAXES;
        }

        return array_map(fn (string $type): array => [
            'Type' => $type,
            'BillShipper' => ['AccountNumber' => $accountNumber],
        ], $types);
    }

    /**
     * `InternationalForms.TermsOfShipment`. UPS prints the terms on the
     * invoice only when this is sent as well as the Type 02 charge; with the
     * charge alone the terms box is blank.
     *
     * @return array{TermsOfShipment?: string}
     */
    private function buildTermsOfShipment(?ResolvedCustomsTerms $terms): array
    {
        return $terms?->dutiesTerms === null
            ? []
            : ['TermsOfShipment' => strtoupper($terms->dutiesTerms->value)];
    }

    /**
     * The shipper number a priced-for-duties rate request is billed under.
     * UPS wants the account on `Shipper` as well as in the charge before it
     * itemizes the duties surcharge.
     *
     * @return array{ShipperNumber?: string}
     */
    private function buildRateShipperNumber(RateRequest $request): array
    {
        $accountNumber = $this->resolveAccountNumber($this->ratingAccount($request));

        return $this->ratesDutiesTerm($request) && filled($accountNumber)
            ? ['ShipperNumber' => $accountNumber]
            : [];
    }

    /**
     * The charges a rate is priced on, sent only where there is a duties term
     * to price: transportation, plus duties and taxes for DDP.
     *
     * @return array{PaymentDetails?: array{ShipmentCharge: list<array{Type: string, BillShipper: array{AccountNumber: string|null}}>}}
     */
    private function buildRatePaymentDetails(RateRequest $request): array
    {
        $accountNumber = $this->resolveAccountNumber($this->ratingAccount($request));

        if (! $this->ratesDutiesTerm($request) || blank($accountNumber)) {
            return [];
        }

        return ['PaymentDetails' => [
            'ShipmentCharge' => $this->buildShipmentCharges($request->customsTerms, $accountNumber),
        ]];
    }

    private function ratesDutiesTerm(RateRequest $request): bool
    {
        return $request->customsTerms !== null
            && $request->customsTerms->applies
            && $request->customsTerms->dutiesTerms !== null;
    }

    /**
     * The seller's registration, on `ShipFrom.VendorInfo`, when one is
     * declared. The Vendor Collect ID prints on the invoice as "IOSS:",
     * "VOEC:" and "ARN:"; a UK VAT number prints with no label.
     *
     * @return array{VendorInfo?: array{VendorCollectIDTypeCode: string, VendorCollectIDNumber: string, ConsigneeType: string}}
     */
    private function buildVendorInfo(ShipRequest $request): array
    {
        $registration = $request->customsTerms?->registration;

        if ($registration === null) {
            return [];
        }

        return ['VendorInfo' => [
            'VendorCollectIDTypeCode' => self::VENDOR_COLLECT_ID_TYPE_CODES[$registration->regime->value],
            'VendorCollectIDNumber' => mb_substr($registration->number, 0, 35),
            'ConsigneeType' => $this->consigneeType($request->toAddress),
        ]];
    }

    /**
     * The client's EIN on the shipper, which the EEI form needs.
     *
     * @return array{TaxIdentificationNumber?: string, TaxIDType?: array{Code: string}}
     */
    private function buildExporterTaxId(ShipRequest $request): array
    {
        return $this->filesEei($request)
            ? ['TaxIdentificationNumber' => (string) $request->exporterEin, 'TaxIDType' => ['Code' => 'EIN']]
            : [];
    }

    /**
     * The consignee's tax ID, as UPS prints it on the invoice: "Tax ID/VAT
     * No.". `ShipTo.TaxIdentificationNumber` is deprecated. A CPF is personal
     * and a CNPJ a company's; any other ID follows whether the consignee has a
     * company name.
     *
     * @return array<string, mixed>|null
     */
    private function buildGlobalTaxInformation(ShipRequest $request): ?array
    {
        $taxId = $request->recipientTaxId;

        if (! $taxId instanceof RecipientTaxId || ! $this->sendsGlobalTaxInformation($request)) {
            return null;
        }

        $isCompany = match ($taxId->type) {
            RecipientTaxIdType::Cpf, RecipientTaxIdType::Pccc => false,
            RecipientTaxIdType::Cnpj => true,
            RecipientTaxIdType::Vat, RecipientTaxIdType::Other => filled($request->toAddress->company),
        };
        $country = strtoupper($request->toAddress->country);

        return [
            'ConsigneeTypeValue' => $this->consigneeType($request->toAddress),
            'AgentTaxIdentificationNumber' => [[
                'AgentRole' => self::AGENT_ROLE_CONSIGNEE,
                'TaxIdentificationNumber' => [[
                    'IdentificationNumber' => $taxId->number,
                    'IDNumberTypeCode' => $isCompany ? self::ID_NUMBER_COMPANY_TAX : self::ID_NUMBER_PERSONAL_TAX,
                    'IDNumberCustomerRole' => '18',
                    'IDNumberEncryptionIndicator' => '0',
                    'IDNumberPurposeCode' => '01',
                    'IDNumberIssuingCntryCd' => $country,
                    'IDNumberRequestingCntryCd' => $country,
                    'IncludeIDNumberOnShippingBrokerageDocs' => '01',
                ]],
            ]],
        ];
    }

    /**
     * Whether the label files EEI: an ITN is declared and the EIN that goes
     * with it is known. The readiness check refuses an ITN with no EIN before
     * the adapter is reached, so the second test only keeps a hand-built
     * request from sending a form CIE refuses.
     *
     * An exemption is never sent. `EEIFilingOption` without form 11 is
     * accepted and ignored, and requesting the EEI form for every parcel just
     * to print `NO EEI 30.37(a)` adds a page and fields for nothing
     * (`international-customs-terms/02`).
     */
    private function filesEei(ShipRequest $request): bool
    {
        return $this->sendsInternationalForms($request)
            && filled($request->exportItn)
            && filled($request->exporterEin);
    }

    /**
     * Whether the request carries `InternationalForms`: the lane crosses a
     * customs zone and there are lines to declare. The duties term, the ITN and
     * the EIN all ride on it.
     */
    private function sendsInternationalForms(ShipRequest $request): bool
    {
        return ! $request->fromAddress->sharesCustomsZoneWith($request->toAddress) && $request->customsItems !== [];
    }

    /**
     * Whether the consignee's tax ID is sent: one is held and the lane crosses
     * a customs zone, whether or not there are lines to declare.
     */
    private function sendsGlobalTaxInformation(ShipRequest $request): bool
    {
        return $request->recipientTaxId instanceof RecipientTaxId
            && ! $request->fromAddress->sharesCustomsZoneWith($request->toAddress);
    }

    /**
     * What {@see CreateShipment()} puts on the wire, answered with the
     * predicates that build the body. DDP is declared by the Type 02 charge
     * whatever the lines; DDU only by the invoice's `TermsOfShipment`, so a
     * request with no invoice declares none.
     */
    public function declaredCustomsTerms(ShipRequest $request): DeclaredCustomsTerms
    {
        $terms = $request->customsTerms;

        $dutiesTerms = match (true) {
            $terms?->dutiesTerms === DutiesTerms::Ddp => DutiesTerms::Ddp,
            $terms?->dutiesTerms !== null && $this->sendsInternationalForms($request) => $terms->dutiesTerms,
            default => null,
        };

        return new DeclaredCustomsTerms(
            dutiesTerms: $dutiesTerms,
            registration: $terms?->registration,
            recipientTaxIdType: $this->sendsGlobalTaxInformation($request) ? $request->recipientTaxId?->type : null,
            exportItn: $this->filesEei($request) ? $request->exportItn : null,
        );
    }

    /**
     * Take the recipient's tax ID and the client's EIN out of text bound for a
     * log or a screen. UPS can echo a rejected number back in its message, and
     * a key-based redaction cannot see it there.
     *
     * @template T of string|array<array-key, mixed>|null
     *
     * @param  T  $value
     * @return T
     */
    private function scrubCustomsIds(string|array|null $value, ShipRequest $request): string|array|null
    {
        $secrets = array_values(array_filter(
            [$request->recipientTaxId?->number, $request->exporterEin],
            fn (?string $secret): bool => filled($secret),
        ));

        if ($secrets === [] || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            return str_replace($secrets, '[REDACTED]', $value);
        }

        array_walk_recursive($value, function (mixed &$leaf) use ($secrets): void {
            if (is_string($leaf)) {
                $leaf = str_replace($secrets, '[REDACTED]', $leaf);
            }
        });

        return $value;
    }

    /**
     * The EEI fields beside the invoice. Each is required: without them UPS
     * refuses the request (128261).
     *
     * @return array<string, mixed>
     */
    private function buildEeiForm(ShipRequest $request): array
    {
        return [
            'EEIFilingOption' => [
                'Code' => self::EEI_SHIPPER_FILED,
                'ShipperFiled' => [
                    'Code' => self::EEI_SHIPPER_FILED_ITN,
                    'Description' => 'ShipperFiled',
                    'PreDepartureITNNumber' => (string) $request->exportItn,
                ],
            ],
            'ExportDate' => ($request->shipDate ?? now())->format('Ymd'),
            'ExportingCarrier' => 'UPS',
            'InBondCode' => self::EEI_NOT_IN_BOND,
            'PointOfOrigin' => (string) $request->fromAddress->stateOrProvince,
            'PointOfOriginType' => self::EEI_POINT_OF_ORIGIN_STATE,
            'ModeOfTransport' => $this->modeOfTransport($request),
            'PartiesToTransaction' => self::EEI_PARTIES_NOT_RELATED,
        ];
    }

    /**
     * How the goods leave the country: by truck on a ground-network service
     * to Canada or Mexico, else by air.
     */
    private function modeOfTransport(ShipRequest $request): string
    {
        $serviceCode = (string) ($request->selectedRate->metadata['serviceCode'] ?? $request->selectedRate->serviceCode);
        $overland = in_array(strtoupper($request->toAddress->country), self::LAND_BORDER_COUNTRIES, true);

        return $overland && in_array($serviceCode, self::GROUND_NETWORK_SERVICES, true)
            ? self::EEI_TRANSPORT_TRUCK
            : self::EEI_TRANSPORT_AIR;
    }

    /**
     * The ship-to as the EEI's ultimate consignee: a direct consumer, or
     * other/unknown when the address has a company name.
     *
     * @return array<string, mixed>
     */
    private function buildUltimateConsignee(ShipRequest $request): array
    {
        $isCompany = filled($request->toAddress->company);
        $address = $this->buildAddress($request->toAddress);

        return [
            'CompanyName' => mb_substr($isCompany ? (string) $request->toAddress->company : $this->buildAttentionName($request->toAddress), 0, 35),
            'Address' => $address,
            // A company may be buying for its own use or to resell, and the
            // order does not say; only an individual is known to be a direct
            // consumer. UPS classifies the rest as Other/Unknown.
            'UltimateConsigneeType' => $isCompany
                ? ['Code' => self::ULTIMATE_CONSIGNEE_OTHER, 'Description' => 'Other/Unknown']
                : ['Code' => self::ULTIMATE_CONSIGNEE_DIRECT_CONSUMER, 'Description' => 'Direct Consumer'],
        ];
    }

    private function consigneeType(AddressData $address): string
    {
        return filled($address->company) ? self::PARTY_TYPE_BUSINESS : self::PARTY_TYPE_CONSUMER;
    }

    /**
     * A product's EU product identifiers as UPS lists them, or null when it
     * lacks the merchant or manufacturer identifier.
     *
     * UPS requires both whenever the exempt indicator is false, and the app has
     * no grounds to say it is true, so a partial list is an invalid body rather
     * than a partial declaration: such a product is sent as it was before the
     * rule and UPS applies its own default. Whether that may be bought at all is
     * decided before the adapter is reached (`eu-product-identifiers/04`). The
     * standard identifier is optional and UPS has no placeholder for it.
     *
     * @return list<array{ProductID: string, ProductIDTypeCode: string}>|null
     */
    private function euProductIdentifiers(CustomsItem $item): ?array
    {
        if ($item->merchantProductId === null || $item->manufacturerProductId === null) {
            return null;
        }

        $identifiers = [
            '0100' => $item->merchantProductId,
            '0200' => $item->manufacturerProductId,
            '0300' => $item->standardProductId,
        ];

        $list = [];

        foreach ($identifiers as $typeCode => $productId) {
            if ($productId !== null) {
                $list[] = [
                    'ProductID' => mb_substr($productId, 0, self::PRODUCT_ID_MAX_LENGTH),
                    'ProductIDTypeCode' => (string) $typeCode,
                ];
            }
        }

        return $list;
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
