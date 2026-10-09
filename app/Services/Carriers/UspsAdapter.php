<?php

namespace App\Services\Carriers;

use App\Contracts\DeclaresSellableServices;
use App\Contracts\DirectCarrierAdapter;
use App\Contracts\RecoversUnresolvedPurchase;
use App\Contracts\SendsCustomsTerms;
use App\Contracts\UsesCarrierAccount;
use App\DataTransferObjects\Customs\DeclaredCustomsTerms;
use App\DataTransferObjects\Customs\RecipientTaxId;
use App\DataTransferObjects\Customs\SellerTaxRegistration;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\DataTransferObjects\Shipping\CustomsItem;
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
use App\Enums\CustomsDocumentDelivery;
use App\Enums\DutiesTerms;
use App\Enums\RecipientTaxIdType;
use App\Enums\ServiceCapability;
use App\Enums\TrackingStatus;
use App\Exceptions\Carriers\CarrierException;
use App\Exceptions\Carriers\UnclassifiablePackagingException;
use App\Exceptions\Carriers\UnreadablePurchaseResponseException;
use App\Exceptions\LabelNotRecoverableException;
use App\Http\Integrations\USPS\Requests\CancelInternationalLabel;
use App\Http\Integrations\USPS\Requests\CancelLabel;
use App\Http\Integrations\USPS\Requests\InternationalLabel;
use App\Http\Integrations\USPS\Requests\InternationalLabelReprint;
use App\Http\Integrations\USPS\Requests\Label;
use App\Http\Integrations\USPS\Requests\LabelReprint;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Http\Integrations\USPS\Requests\TrackShipment;
use App\Http\Integrations\USPS\Responses\LabelResponse;
use App\Http\Integrations\USPS\USPSConnector;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Services\AddressReferenceService;
use App\Services\Carriers\Concerns\BuildsCustomerReferences;
use App\Services\Carriers\Concerns\ConsultsCarrierPolicyForOffers;
use App\Services\Carriers\Concerns\DecodesJsonResponses;
use App\Services\Carriers\Concerns\HasDefaultServiceCapabilities;
use App\Services\Carriers\Concerns\IdentifiesCatalogServices;
use App\Services\Carriers\Concerns\ResolvesCarrierAccount;
use App\Services\Carriers\Concerns\ResolvesDeliveredAt;
use App\Services\Shipping\ContentsFilter;
use App\Support\LabelText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\ServerException;
use Saloon\Exceptions\Request\Statuses\ForbiddenException;
use Saloon\Exceptions\Request\Statuses\RequestTimeOutException;
use Saloon\Http\Response;
use voku\helper\ASCII;

class UspsAdapter implements DeclaresSellableServices, DirectCarrierAdapter, RecoversUnresolvedPurchase, SendsCustomsTerms, UsesCarrierAccount
{
    use BuildsCustomerReferences;
    use ConsultsCarrierPolicyForOffers;
    use DecodesJsonResponses;
    use HasDefaultServiceCapabilities;
    use IdentifiesCatalogServices;
    use ResolvesCarrierAccount;
    use ResolvesDeliveredAt;

    /**
     * The mail classes this integration can sell a single parcel under: the
     * ones {@see self::SHIPPER_PACKAGING_INDICATORS} prices. A class that is
     * not there is dropped from every response, so an account asked only for
     * it would quote nothing and still count as a seller.
     */
    public function sellsService(string $serviceCode): bool
    {
        return array_key_exists($serviceCode, self::SHIPPER_PACKAGING_INDICATORS);
    }

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
     * Values of `customsForm.incoterm`, which USPS uses for the commerce type
     * (not DDP/DDU). "3" consumer to consumer and "4" consumer to business are
     * never sent: the shipper is always a business.
     */
    private const COMMERCE_TYPE_BUSINESS_TO_CONSUMER = '1';

    private const COMMERCE_TYPE_BUSINESS_TO_BUSINESS = '2';

    /**
     * The longest `reference` a `customsForm` importer or exporter reference
     * takes. A longer number is left out rather than cut: a truncated tax ID
     * or registration is a wrong one.
     */
    private const MAX_CUSTOMS_REFERENCE_LENGTH = 28;

    /**
     * The 400 USPS answers a `prepayDutiesTaxesFees` label with when it cannot
     * prepay duties to the destination: "DDP is not available for the provided
     * country and product options." It means `duties-support.json` says DDP
     * works there and USPS says it does not.
     */
    private const DDP_NOT_AVAILABLE = '030031';

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
        self::REPRINT_KEY_NOT_FOUND => 'USPS has no label for the earlier purchase attempt; nothing was bought.',
        self::REPRINT_LABEL_CANCELLED => 'The label from the earlier purchase attempt has since been cancelled at USPS.',
    ];

    /**
     * The two reprint errors that settle an unresolved purchase, both observed
     * in production on 2026-09-18 (`postage-source-split/18`): no label was
     * ever bought under the key, or one was and has since been cancelled.
     * Either way nothing usable exists and the package may be quoted again.
     * Every other reprint error leaves the question open, and so does "not
     * found" for an attempt older than USPS looks back — see
     * {@see keyLookupCovers()}.
     */
    private const REPRINT_KEY_NOT_FOUND = '160412';

    private const REPRINT_LABEL_CANCELLED = '160979';

    /**
     * "Label is unavailable for reprint past its mailingDate of …", seen in
     * TEM 2026-10-02 on a confirmed sale whose Label was never saved, three
     * days after it was bought. The label exists and USPS will not hand it
     * over again. USPS documents the rule: "Labels can only be reprinted up to
     * the mailing date", which is the ship date the purchase sent, so a label
     * is recoverable through its ship date and no later.
     */
    private const REPRINT_PAST_MAILING_DATE = '160981';

    /**
     * How far back, in days, USPS finds a label by its idempotency key when
     * its error does not say: production's "for a mailing date within the
     * last 7 days" (2026-09-18). TEM says 14.
     */
    private const REPRINT_LOOKBACK_DAYS = 7;

    /**
     * The extra service whose price is the duties, taxes and fees USPS prepaid on
     * a DDP label (service 370 is the DDP fee itself, priced at zero).
     */
    private const PREPAID_DUTIES_SERVICE_ID = '371';

    /**
     * Where the purchase's `X-Idempotency-Key` lives on the offer.
     *
     * The only thing a direct USPS offer's `purchase_context` holds. It is
     * recorded before the label request leaves, so a reply that never arrives
     * still leaves behind the handle the reprint is asked by.
     */
    public const PURCHASE_CONTEXT_KEY = 'idempotency_key';

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
        return Carrier::USPS;
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

        $account = $this->ratingAccount($request);
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

        $account = $this->ratingAccount($request);
        $connector = USPSConnector::getAuthenticatedConnector($account);
        $apiRequest = $this->buildRateApiRequest($request, $account);
        $pendingRequest = $connector->createPendingRequest($apiRequest);

        return new PreparedRateRequest(
            pendingRequest: $pendingRequest,
            carrierName: Carrier::USPS,
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

        // Read from the request again rather than carried from
        // prepareRateRequest(): the async path parses in a different call from
        // the one that prepared, and the account is what the offer records as
        // having quoted this price.
        $account = $this->ratingAccount($request);

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
                    carrier: Carrier::USPS,
                    serviceCode: $rate['mailClass'],
                    serviceName: $rate['description'] ?? $rate['mailClass'],
                    price: (float) ($rateOption['totalBasePrice'] ?? 0),
                    deliveryCommitment: $rateOption['commitment']['name'] ?? null,
                    deliveryDate: $rateOption['commitment']['scheduleDeliveryDate'] ?? null,
                    metadata: $metadata,
                    packagingRequirement: $this->classifyPackaging($metadata),
                    carrierAccountId: $account?->id,
                ));
            }
        }

        logger()->debug('USPS rate response filtering', [
            'total_api_rates' => $totalApiRates,
            'matched_rates' => $results->count(),
            'requested_codes' => $serviceCodes,
        ]);

        return $this->withCatalogIdentity($results);
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

    /**
     * Buy a label, under a key the purchase can later be asked about.
     *
     * A connection failure, a timeout or a 5xx on the label request is *not*
     * caught here, unlike every other error: the label may exist and be paid
     * for — a server error can arrive after the label was created — and
     * turning that into a failed response would settle the offer as a decline
     * and let the next attempt buy a second one. The exception leaves the
     * offer unresolved, and {@see recoverPurchase()} asks USPS by the key
     * before anything else is bought — ADR-0002 decision 4's fifth property.
     * A 2xx whose reply cannot be read is the same unknown, and is thrown as
     * {@see UnreadablePurchaseResponseException} for the same reason.
     */
    public function createShipment(ShipRequest $request): ShipResponse
    {
        $isInternational = $request->toAddress->country !== 'US';

        // Before the key is issued and before anything is sent: nothing was
        // asked of USPS, so nothing can have been bought.
        if ($isInternational && ($unromanizable = $this->unromanizableAddressField($request)) !== null) {
            return ShipResponse::failure("USPS needs an international address written in roman letters, and the {$unromanizable} has characters that cannot be romanized automatically. Enter a romanized address on the shipment and buy the label again.");
        }

        if ($isInternational && ($refusal = $this->prepaidDutiesRefusal($request)) !== null) {
            return ShipResponse::failure($refusal);
        }

        $idempotencyKey = $this->issueIdempotencyKey($request);

        return $isInternational
            ? $this->createInternationalShipment($request, $idempotencyKey)
            : $this->createDomesticShipment($request, $idempotencyKey);
    }

    /**
     * A fresh `X-Idempotency-Key`, stored on the offer before it is spent.
     *
     * USPS asks for a UUID unique per CRID across all label requests. It is
     * a handle, not a guard — the label endpoint buys again under a repeated
     * key — so it is minted per purchase rather than derived from the offer,
     * and written to the offer's encrypted `purchase_context` first: the
     * window this exists for opens the moment the request leaves.
     */
    private function issueIdempotencyKey(ShipRequest $request): string
    {
        $key = (string) Str::uuid();

        $request->offer?->update(['purchase_context' => [self::PURCHASE_CONTEXT_KEY => $key]]);

        return $key;
    }

    /**
     * Ask USPS whether a spent offer bought a label, by the key it was sent under.
     *
     * The reprint endpoint is the question: side-effect free, and a 200 is the
     * label already paid for, so the package ships on it. `160412` is USPS
     * being certain no label exists under the key, and `160979` that the one
     * that did has been cancelled — both settle the offer as declined. Anything
     * else, including a transport error, leaves it unresolved.
     *
     * Asked on the account the offer was bought on, never the one scopes
     * prefer now: keys are per CRID, so another account's "not found" says
     * nothing about the label — see {@see purchasingAccountChanged()}.
     *
     * An offer with no key recorded predates the key being sent (or was spent
     * by a path with nowhere to store it); nobody can be asked about it, so
     * it is settled the way `14`'s carve-out settled every direct offer —
     * the alternative strands the package behind a question with no answer.
     */
    /**
     * Whether a "key not found" can be believed for this attempt.
     *
     * USPS keeps keys only for labels whose mailing date falls inside its
     * look-back, and says how long that is in the error itself. A mailing date
     * is never before the purchase, so an attempt inside the window is one
     * USPS would have found; outside it, a label bought under the key reads
     * exactly like none. A day short of the window, for the difference between
     * our clock and USPS's mailing day.
     *
     * @param  array<string, mixed>  $payload
     */
    private function keyLookupCovers(ShippingOffer $offer, array $payload): bool
    {
        if ($offer->consumed_at === null) {
            return false;
        }

        $days = self::REPRINT_LOOKBACK_DAYS;

        foreach ($payload['error']['errors'] ?? [] as $error) {
            if ((string) ($error['code'] ?? '') === self::REPRINT_KEY_NOT_FOUND
                && preg_match('/within the last (\d+) days/i', (string) ($error['detail'] ?? ''), $matches) === 1) {
                $days = (int) $matches[1];
            }
        }

        return $offer->consumed_at->isAfter(now()->subDays(max($days - 1, 0)));
    }

    public function recoverPurchase(ShipRequest $request): ?ShipResponse
    {
        $offer = $request->offer;
        $key = $offer?->purchase_context[self::PURCHASE_CONTEXT_KEY] ?? null;

        if ($offer === null || ! is_string($key) || $key === '') {
            return ShipResponse::failure('No idempotency key was recorded for the earlier purchase attempt, so USPS cannot be asked about it; settled as not bought.');
        }

        if ($this->purchasingAccountChanged($offer)) {
            Log::channel('usps-validation')->warning('Cannot ask USPS about a purchase: the account it was bought on is gone or bills someone else now', [
                'offer' => $offer->public_id,
                'carrier_account_id' => $offer->carrier_account_id,
            ]);

            return null;
        }

        $isInternational = $request->toAddress->country !== 'US';
        $account = $this->purchasingAccount($offer, $request->locationId, $request->clientId);

        try {
            $connector = USPSConnector::getAuthenticatedConnector($account);
            $paymentAuthorizationToken = USPSConnector::getUspsPaymentAuthorizationToken($account?->id);

            $imageInfo = [
                'imageType' => match (true) {
                    $request->labelFormat !== 'zpl' => 'PDF',
                    $request->labelDpi === 300 => 'ZPL300DPI',
                    default => 'ZPL203DPI',
                },
                'labelType' => '4X6LABEL',
            ];

            $apiRequest = $isInternational
                ? new InternationalLabelReprint($key, $imageInfo)
                : new LabelReprint($key, $imageInfo);
            $apiRequest->headers()->add('X-Payment-Authorization-Token', $paymentAuthorizationToken);

            $response = $connector->send($apiRequest);
        } catch (RequestException $e) {
            $response = $e->getResponse();
        } catch (\Exception $e) {
            Log::channel('usps-validation')->warning('Could not ask USPS what became of a purchase', [
                'offer' => $offer->public_id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            $payload = $this->decodeJsonSafely($response);
            $codes = array_map(fn (array $error): string => (string) ($error['code'] ?? ''), $payload['error']['errors'] ?? []);

            Log::channel('usps-validation')->info('USPS reprint by idempotency key did not return a label', [
                'offer' => $offer->public_id,
                'status' => $response->status(),
                'codes' => $codes,
            ]);

            if (in_array(self::REPRINT_LABEL_CANCELLED, $codes, true)) {
                return ShipResponse::failure($this->describeLabelError($payload));
            }

            if (in_array(self::REPRINT_PAST_MAILING_DATE, $codes, true)) {
                Log::channel('usps-validation')->warning('USPS will not reprint a label past its mailing date', [
                    'offer' => $offer->public_id,
                    'detail' => $this->describeLabelError($payload),
                ]);

                $mailingDate = preg_match('/mailingDate of (\d{4}-\d{2}-\d{2})/', $this->describeLabelError($payload), $matches)
                    ? ' of '.CarbonImmutable::parse($matches[1])->format('M j, Y')
                    : '';

                throw new LabelNotRecoverableException("USPS will not send a label again after its mailing date{$mailingDate}.");
            }

            if (in_array(self::REPRINT_KEY_NOT_FOUND, $codes, true)) {
                if ($this->keyLookupCovers($offer, $payload)) {
                    return ShipResponse::failure($this->describeLabelError($payload));
                }

                // Older than USPS looks back: "not found" is all it can say
                // about any key that old, a real label's included. Unknown,
                // so the package waits for a person (`postage-source-split/16`).
                Log::channel('usps-validation')->warning('USPS cannot look back far enough to say what became of a purchase', [
                    'offer' => $offer->public_id,
                    'attempted_at' => $offer->consumed_at?->toIso8601String(),
                ]);
            }

            return null;
        }

        try {
            /** @var LabelResponse $response */
            $response->parseBody();
        } catch (\Exception $e) {
            Log::channel('usps-validation')->error('USPS reprint returned a label that could not be read', [
                'offer' => $offer->public_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $trackingNumber = $response->metadata['internationalTrackingNumber']
            ?? $response->metadata['trackingNumber']
            ?? null;

        if (empty($trackingNumber) || $response->label === '') {
            return null;
        }

        Log::channel('usps-validation')->info('Recovered a USPS label by idempotency key', [
            'offer' => $offer->public_id,
            'tracking_number' => $trackingNumber,
            'reprint' => $response->reprintInfo,
        ]);

        return ShipResponse::success(
            trackingNumber: $trackingNumber,
            cost: (float) ($response->metadata['postage'] ?? $request->selectedRate->price),
            carrier: Carrier::USPS,
            service: $request->selectedRate->serviceName,
            labelData: $response->label,
            // The same orientation the purchase path records for each API.
            labelOrientation: $isInternational ? 'landscape' : 'portrait',
            labelFormat: $request->labelFormat,
            labelDpi: $request->labelDpi,
            shipDate: $request->shipDate,
            carrierAccountId: $account?->id,
            dutiesCost: $isInternational ? $this->prepaidDutiesCost($response->metadata, $request) : null,
        );
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

        $connector = USPSConnector::getAuthenticatedConnector($account);

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

    private function createDomesticShipment(ShipRequest $request, string $idempotencyKey): ShipResponse
    {
        $accepted = null;

        try {
            $account = $this->resolveAccount($request->locationId, $request->clientId);
            $connector = USPSConnector::getAuthenticatedConnector($account);
            $paymentAuthorizationToken = USPSConnector::getUspsPaymentAuthorizationToken($account?->id);

            $apiRequest = new Label;
            $apiRequest->headers()->set([
                'X-Payment-Authorization-Token' => $paymentAuthorizationToken,
                'X-Idempotency-Key' => $idempotencyKey,
            ]);

            $toAddress = $this->buildDomesticAddress($request->toAddress, $request);
            $fromAddress = $this->buildDomesticAddress($request->fromAddress, $request);

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

            // A 5xx is not a refusal — see createShipment(). Thrown, so it
            // reaches the rethrow below rather than the decline path.
            if ($response->serverError()) {
                $response->throw();
            }

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

            /** @var LabelResponse $response */
            $accepted = $response;
            $trackingNumber = $this->readPurchasedLabel($response, 'createDomesticShipment', $idempotencyKey);

            return ShipResponse::success(
                trackingNumber: $trackingNumber,
                cost: (float) ($response->metadata['postage'] ?? $request->selectedRate->price),
                carrier: Carrier::USPS,
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
        } catch (FatalRequestException|RequestTimeOutException|ServerException $e) {
            // No answer is not a refusal — see createShipment().
            Log::channel('usps-validation')->warning('USPS createDomesticShipment got no answer; the offer stays unresolved', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'idempotency_key' => $idempotencyKey,
            ]);

            throw $e;
        } catch (UnreadablePurchaseResponseException $e) {
            // Accepted and charged — see readPurchasedLabel().
            throw $e;
        } catch (\Throwable $e) {
            if ($accepted !== null) {
                // Anything that breaks after the 2xx is still an accepted purchase.
                $this->unreadablePurchase($accepted, 'createDomesticShipment', $idempotencyKey, $e->getMessage(), previous: $e);
            }

            if (! $e instanceof \Exception) {
                throw $e;
            }

            Log::channel('usps-validation')->error('USPS createDomesticShipment error', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ShipResponse::failure($this->describeLabelException($e));
        }
    }

    private function createInternationalShipment(ShipRequest $request, string $idempotencyKey): ShipResponse
    {
        $accepted = null;

        try {
            $account = $this->resolveAccount($request->locationId, $request->clientId);
            $connector = USPSConnector::getAuthenticatedConnector($account);
            $paymentAuthorizationToken = USPSConnector::getUspsPaymentAuthorizationToken($account?->id);

            $apiRequest = new InternationalLabel;
            $apiRequest->headers()->set([
                'X-Payment-Authorization-Token' => $paymentAuthorizationToken,
                'X-Idempotency-Key' => $idempotencyKey,
            ]);

            $toAddress = $this->buildInternationalAddress($request->toAddress, $request);
            $fromAddress = $this->buildDomesticAddress($request->fromAddress, $request);

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
                    ...($this->prepaysDuties($request) ? ['prepayDutiesTaxesFees' => true] : []),
                ],
                'customsForm' => $this->buildCustomsForm($request),
                'imageInfo' => $imageInfo,
            ];

            $apiRequest->body()->set($body);

            Log::channel('usps-validation')->debug('LABEL REQUEST', [
                'payload' => $body,
            ]);

            $response = $connector->send($apiRequest);

            // A 5xx is not a refusal — see createShipment(). Thrown, so it
            // reaches the rethrow below rather than the decline path.
            if ($response->serverError()) {
                $response->throw();
            }

            if (! $response->successful()) {
                $payload = $this->decodeJsonSafely($response);
                $errorMessage = $this->prepaidDutiesDeclined($payload, $request) ?? $this->describeLabelError($payload);
                Log::channel('usps-validation')->error('USPS createInternationalShipment API error', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                    'body' => $payload,
                ]);

                return ShipResponse::failure($errorMessage);
            }

            /** @var LabelResponse $response */
            $accepted = $response;
            $trackingNumber = $this->readPurchasedLabel($response, 'createInternationalShipment', $idempotencyKey);

            return ShipResponse::success(
                trackingNumber: $trackingNumber,
                cost: (float) ($response->metadata['postage'] ?? $request->selectedRate->price),
                carrier: Carrier::USPS,
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
                dutiesCost: $this->prepaidDutiesCost($response->metadata, $request),
            );
        } catch (FatalRequestException|RequestTimeOutException|ServerException $e) {
            // No answer is not a refusal — see createShipment().
            Log::channel('usps-validation')->warning('USPS createInternationalShipment got no answer; the offer stays unresolved', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'idempotency_key' => $idempotencyKey,
            ]);

            throw $e;
        } catch (UnreadablePurchaseResponseException $e) {
            // Accepted and charged — see readPurchasedLabel().
            throw $e;
        } catch (\Throwable $e) {
            if ($accepted !== null) {
                // Anything that breaks after the 2xx is still an accepted purchase.
                $this->unreadablePurchase($accepted, 'createInternationalShipment', $idempotencyKey, $e->getMessage(), previous: $e);
            }

            if (! $e instanceof \Exception) {
                throw $e;
            }

            Log::channel('usps-validation')->error('USPS createInternationalShipment error', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ShipResponse::failure($this->describeLabelException($e));
        }
    }

    /**
     * Read the label out of a 2xx purchase reply, or throw.
     *
     * A 2xx from the label endpoint means USPS created the label and charged
     * for it, so nothing past this point may come back as a decline: a
     * malformed multipart body, a missing tracking number or an empty label
     * part is an unknown outcome. {@see UnreadablePurchaseResponseException}
     * leaves the offer unresolved, and {@see recoverPurchase()} reprints the
     * same label by the idempotency key on the next attempt. The raw body is
     * logged first because it is the only copy of what USPS said.
     *
     * @return string The tracking number; the label is on the response.
     *
     * @throws UnreadablePurchaseResponseException
     */
    private function readPurchasedLabel(LabelResponse $response, string $operation, string $idempotencyKey): string
    {
        try {
            $response->parseBody();
        } catch (\Throwable $e) {
            // The metadata part is read before the parts are counted, so a
            // reply missing its label can still name the tracking number.
            $reported = $response->metadata['internationalTrackingNumber'] ?? $response->metadata['trackingNumber'] ?? null;

            $this->unreadablePurchase(
                $response,
                $operation,
                $idempotencyKey,
                $e->getMessage(),
                is_scalar($reported) && (string) $reported !== '' ? (string) $reported : null,
                $e,
            );
        }

        Log::channel('usps-validation')->debug('LABEL RESPONSE', [
            'metadata' => $response->metadata,
        ]);

        // International responses use 'internationalTrackingNumber' instead of 'trackingNumber'
        $trackingNumber = $response->metadata['internationalTrackingNumber']
            ?? $response->metadata['trackingNumber']
            ?? null;

        if (! is_scalar($trackingNumber) || (string) $trackingNumber === '') {
            $this->unreadablePurchase($response, $operation, $idempotencyKey, 'USPS response missing tracking number');
        }

        if ($response->label === '') {
            $this->unreadablePurchase($response, $operation, $idempotencyKey, 'USPS response missing label data', (string) $trackingNumber);
        }

        return (string) $trackingNumber;
    }

    /**
     * @throws UnreadablePurchaseResponseException
     */
    private function unreadablePurchase(
        Response $response,
        string $operation,
        string $idempotencyKey,
        string $reason,
        ?string $trackingNumber = null,
        ?\Throwable $previous = null,
    ): never {
        Log::channel('usps-validation')->error("USPS {$operation} accepted the purchase but its reply could not be read; the offer stays unresolved", [
            'reason' => $reason,
            'status' => $response->status(),
            'idempotency_key' => $idempotencyKey,
            'tracking_number' => $trackingNumber,
            'content_type' => $response->headers()->get('Content-Type'),
            'body' => $response->body(),
        ]);

        throw new UnreadablePurchaseResponseException(Carrier::USPS, $reason, $trackingNumber, $previous);
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
        $commerceType = $this->euCommerceType($request->toAddress);
        $contents = [];

        foreach ($request->customsItems as $item) {
            $contentItem = [
                'itemDescription' => mb_substr($item->description, 0, 30),
                'itemQuantity' => $item->quantity,
                'itemTotalValue' => round($item->unitValue * $item->quantity, 2),
                'weightUOM' => 'lb',
                'itemTotalWeight' => round($item->weight * $item->quantity, 4),
            ];

            // Never an origin the product does not have: a missing one is
            // refused by CustomsReadiness before the label is bought.
            if (filled($item->countryOfOrigin)) {
                $contentItem['countryofOrigin'] = $item->countryOfOrigin;
            }

            if ($item->hsTariffNumber) {
                $contentItem['HSTariffNumber'] = $item->hsTariffNumber;
            }

            if ($commerceType !== null && ($productIds = $this->euProductIdentifiers($item)) !== null) {
                $contentItem['europeanUnionProductID'] = $productIds;
            }

            $contents[] = $contentItem;
        }

        // customerReference reaches USPS on a customs-bearing label but is only
        // written to the Shipping Services File — USPS does not print it
        // alongside a customs form. invoiceNumber is the field that shows up on
        // the form itself, so the reference is repeated there to keep an
        // international label matchable to its package.
        $reference = $this->labelReferences($request, maxLength: 30, maxCount: 1)[0] ?? null;
        $exporterReference = $this->exporterReference($request);
        $importerReference = $this->importerReference($request);

        return [
            'AESITN' => $this->exportFilingReference($request),
            'customsContentType' => 'MERCHANDISE',
            ...($reference !== null ? ['invoiceNumber' => $reference] : []),
            // USPS names this field `incoterm`, but it holds the commerce type.
            // It has nothing to do with the duties terms (DDP/DDU).
            ...($commerceType !== null ? ['incoterm' => $commerceType] : []),
            ...($exporterReference !== null ? ['exportersReference' => $exporterReference] : []),
            ...($importerReference !== null ? ['importersReference' => $importerReference] : []),
            'contents' => $contents,
        ];
    }

    /**
     * Whether the label crosses a customs border on the international
     * endpoint, which is where the duties terms, registration and recipient tax
     * ID are declared. A domestic label with a customs form (APO, FPO, DPO)
     * declares none of them.
     */
    private function declaresCustomsTerms(ShipRequest $request): bool
    {
        return strtoupper(trim($request->toAddress->country)) !== 'US' && $request->customsItems !== [];
    }

    /**
     * Whether this label asks USPS to prepay duties, taxes and fees: the
     * Shipment resolved DDP. `prepayDutiesTaxesFees` is sent for DDP and for
     * nothing else; DDU is USPS's default.
     */
    private function prepaysDuties(ShipRequest $request): bool
    {
        return $this->declaresCustomsTerms($request)
            && $request->customsTerms?->dutiesTerms === DutiesTerms::Ddp;
    }

    /**
     * The seller registration as `customsForm.exportersReference`, printed as
     * the label's Exporter's reference. USPS reads any registration as a
     * `VAT_NUMBER` whatever its regime, and drops `prepayDutiesTaxesFees`
     * silently when any exporter reference is present, so this is sent only on
     * a DDU label ({@see self::prepaidDutiesRefusal()} refuses the rest).
     *
     * @return array{referenceType: string, reference: string}|null
     */
    private function exporterReference(ShipRequest $request): ?array
    {
        $registration = $this->sentRegistration($request);

        return $registration === null ? null : ['referenceType' => 'VAT_NUMBER', 'reference' => $registration->number];
    }

    /**
     * The registration this request declares: the resolved one, when the label
     * declares customs terms and the number fits the field.
     */
    private function sentRegistration(ShipRequest $request): ?SellerTaxRegistration
    {
        $registration = $request->customsTerms?->registration;

        return $this->declaresCustomsTerms($request) && $registration !== null && $this->fitsCustomsReference($registration->number)
            ? $registration
            : null;
    }

    /**
     * The recipient's tax ID as `customsForm.importersReference`, printed as
     * the Importer's Reference. It never interferes with DDP. A Brazilian CPF
     * is a `TAX_CODE`; a VAT number is a `VAT_NUMBER`; the other IDs are tax
     * codes too, since none is an importer code.
     *
     * @return array{referenceType: string, reference: string}|null
     */
    private function importerReference(ShipRequest $request): ?array
    {
        $taxId = $this->sentRecipientTaxId($request);

        if ($taxId === null) {
            return null;
        }

        return [
            'referenceType' => $taxId->type === RecipientTaxIdType::Vat ? 'VAT_NUMBER' : 'TAX_CODE',
            'reference' => $taxId->number,
        ];
    }

    private function sentRecipientTaxId(ShipRequest $request): ?RecipientTaxId
    {
        $taxId = $request->recipientTaxId;

        return $this->declaresCustomsTerms($request) && $taxId instanceof RecipientTaxId && $this->fitsCustomsReference($taxId->number)
            ? $taxId
            : null;
    }

    private function fitsCustomsReference(?string $number): bool
    {
        return filled($number) && mb_strlen((string) $number) <= self::MAX_CUSTOMS_REFERENCE_LENGTH;
    }

    /**
     * Why this label must not be sent, when it asks for prepaid duties and
     * USPS would not buy it as asked. Checked before any request, so nothing
     * is bought.
     *
     * - **A registration with DDP.** Any exporter reference makes USPS drop
     *   `prepayDutiesTaxesFees` without a warning, and the label is bought
     *   DDU while PolyBag records DDP. `duties-support.json` already drops
     *   these rates, but its table is keyed by country and cannot express
     *   Northern Ireland, so the adapter is the backstop.
     * - **An account that has not accepted USPS's DDP terms.** Sending the flag
     *   is agreement to them, and the account holder is billed.
     */
    private function prepaidDutiesRefusal(ShipRequest $request): ?string
    {
        if (! $this->prepaysDuties($request)) {
            return null;
        }

        if (($registration = $this->sentRegistration($request)) !== null) {
            $country = $this->countryName($request->toAddress->country);

            return "USPS cannot prepay duties and taxes on a parcel that declares a seller registration ({$registration->regime->value} {$registration->number}): "
                ."any exporter reference makes USPS drop the prepayment and bill the recipient instead. Nothing was bought. To {$country}, ship on DDU terms or with another carrier.";
        }

        $account = $this->resolveAccount($request->locationId, $request->clientId);

        if ($account === null || ! $account->hasAcceptedDdpTerms()) {
            return 'USPS DDP: account terms not accepted. An Admin must accept the USPS prepaid-duties terms on the USPS carrier account before this label can be bought with duties prepaid.';
        }

        return null;
    }

    /**
     * The decline for USPS's "DDP is not available" refusal, naming the
     * destination, or null for any other error. It is not an unexpected error:
     * it means the support table disagrees with USPS.
     *
     * @param  array<array-key, mixed>|null  $payload
     */
    private function prepaidDutiesDeclined(?array $payload, ShipRequest $request): ?string
    {
        if (! $this->prepaysDuties($request)) {
            return null;
        }

        $codes = array_map(fn (mixed $error): string => is_array($error) ? (string) ($error['code'] ?? '') : '', $payload['error']['errors'] ?? []);

        if (! in_array(self::DDP_NOT_AVAILABLE, $codes, true)) {
            return null;
        }

        $country = $this->countryName($request->toAddress->country);

        Log::channel('usps-validation')->warning('USPS cannot prepay duties where duties-support.json says it can', [
            'destination' => strtoupper($request->toAddress->country),
        ]);

        return "USPS cannot prepay duties and taxes to {$country} for these products (DDP is not available for the provided country and product options). Nothing was bought. Ship to {$country} on DDU terms or with another carrier.";
    }

    private function countryName(string $country): string
    {
        return app(AddressReferenceService::class)->getCountryOptions()[strtoupper($country)] ?? strtoupper($country);
    }

    /**
     * The duties and taxes USPS prepaid, from the label's metadata. The total
     * is the price of the "Prepaid Duties, Taxes, and Fees" extra service
     * ({@see self::PREPAID_DUTIES_SERVICE_ID}), which the purchase and the
     * reprint-by-key reply both carry, so a recovered purchase records it too.
     * The `prepaidDutiesTaxesFees` breakdown, which only the purchase reply
     * has, is the fallback: the package fee plus each item's duty and tax, which
     * can differ from the total by a cent or two of USPS rounding. Null when
     * USPS reports neither, which is what a DDU label does. A DDP label with
     * neither is logged, since it means USPS bought it as DDU. Postage is
     * unchanged by it.
     *
     * @param  array<array-key, mixed>  $metadata
     */
    private function prepaidDutiesCost(array $metadata, ShipRequest $request): ?float
    {
        foreach (is_array($metadata['extraServices'] ?? null) ? $metadata['extraServices'] : [] as $service) {
            if (is_array($service)
                && (string) ($service['serviceID'] ?? '') === self::PREPAID_DUTIES_SERVICE_ID
                && is_numeric($service['price'] ?? null)
                && (float) $service['price'] > 0) {
                return round((float) $service['price'], 2);
            }
        }

        $prepaid = $metadata['prepaidDutiesTaxesFees'] ?? null;

        if (! is_array($prepaid)) {
            if ($this->prepaysDuties($request)) {
                Log::channel('usps-validation')->warning('USPS accepted a DDP label but reported no prepaid duties', [
                    'destination' => strtoupper($request->toAddress->country),
                ]);
            }

            return null;
        }

        $total = is_numeric($prepaid['packageFee'] ?? null) ? (float) $prepaid['packageFee'] : 0.0;

        foreach (is_array($prepaid['itemCosts'] ?? null) ? $prepaid['itemCosts'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $total += (is_numeric($item['dutyPrice'] ?? null) ? (float) $item['dutyPrice'] : 0.0)
                + (is_numeric($item['taxPrice'] ?? null) ? (float) $item['taxPrice'] : 0.0);
        }

        return round($total, 2);
    }

    /**
     * What `createInternationalShipment()` puts on the wire, answered with the
     * predicates that build the body: the term is declared with the customs
     * declaration, and each registration or tax ID only when it fits its
     * reference. The ITN is the filing reference when EEI was filed, never the
     * exemption `AESITN` falls back to.
     */
    public function declaredCustomsTerms(ShipRequest $request): DeclaredCustomsTerms
    {
        if (! $this->declaresCustomsTerms($request)) {
            return DeclaredCustomsTerms::none();
        }

        return new DeclaredCustomsTerms(
            dutiesTerms: $request->customsTerms?->dutiesTerms,
            registration: $this->sentRegistration($request),
            recipientTaxIdType: $this->sentRecipientTaxId($request)?->type,
            exportItn: filled($request->exportItn) ? (string) $request->exportItn : null,
        );
    }

    /**
     * What USPS's `customsForm.incoterm` declares for an EU destination:
     * "1" business to consumer, "2" business to business. The adapter always
     * sends merchandise from a business, so only the consignee varies, and a
     * consignee with no company name is a consumer (as in UpsAdapter's
     * ConsigneeType). "3" and "4" are never sent. Null outside the EU.
     */
    private function euCommerceType(AddressData $to): ?string
    {
        if (! $to->isInEuropeanUnion()) {
            return null;
        }

        return filled($to->company) ? self::COMMERCE_TYPE_BUSINESS_TO_BUSINESS : self::COMMERCE_TYPE_BUSINESS_TO_CONSUMER;
    }

    /**
     * The `europeanUnionProductID` block for one line, or null when the line
     * lacks a merchant or manufacturer identifier (after USPS's character
     * limits are applied). A partial block is an invalid body. The standard
     * identifier is added only when there is one, never as a placeholder.
     *
     * @return array{merchantProductIdentifier: string, nonstandardizedManufacturerProductIdentifier: string, standardizedManufacturerProductIdentifier?: string}|null
     */
    private function euProductIdentifiers(CustomsItem $item): ?array
    {
        $merchant = $this->alphanumericProductIdentifier($item->merchantProductId, 50);
        $manufacturer = $this->alphanumericProductIdentifier($item->manufacturerProductId, 70);

        if ($merchant === null || $manufacturer === null) {
            return null;
        }

        $standard = $this->alphanumericProductIdentifier($item->standardProductId, 50);

        return [
            'merchantProductIdentifier' => $merchant,
            'nonstandardizedManufacturerProductIdentifier' => $manufacturer,
            ...($standard !== null ? ['standardizedManufacturerProductIdentifier' => $standard] : []),
        ];
    }

    /**
     * USPS's identifier patterns allow only letters and digits. Until USPS says
     * how punctuated SKUs are meant to be sent, strip everything else and cut
     * to the field's length; an identifier left empty counts as missing. This
     * is the only place that changes if USPS answers differently.
     */
    private function alphanumericProductIdentifier(?string $identifier, int $maxLength): ?string
    {
        if ($identifier === null) {
            return null;
        }

        $stripped = mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $identifier) ?? '', 0, $maxLength);

        return $stripped === '' ? null : $stripped;
    }

    /**
     * What `AESITN` declares: the Shipment's ITN when EEI was filed, else the
     * Canada exemption (15 CFR 30.36), else the $2,500 exemption (30.37(a)).
     * `CustomsReadiness` has already refused a parcel that needs an ITN and
     * has none, so the exemption named here is one the parcel qualifies for.
     */
    private function exportFilingReference(ShipRequest $request): string
    {
        if (filled($request->exportItn)) {
            return (string) $request->exportItn;
        }

        return strtoupper(trim($request->toAddress->country)) === 'CA' ? 'NO EEI 30.36' : 'NO EEI 30.37(a)';
    }

    public function cancelShipment(string $trackingNumber, Package $package): CancelResponse
    {
        try {
            // The account that bought the label — see labelAccount().
            $account = $this->labelAccount($package);
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

            if (! $response->successful()) {
                return CancelResponse::failure('USPS returned status '.$response->status());
            }

            return $this->readCancelReply($response, $trackingNumber);
        } catch (\Exception $e) {
            return CancelResponse::failure($e->getMessage());
        }
    }

    /**
     * A 2xx is not USPS's answer: the v3 cancel reply's `status` is. Two of
     * them void the label. `CANCELED` — no Shipping Services File yet, so the
     * label is simply cancelled (observed in production as
     * `{"trackingNumber": …, "status": "CANCELED"}`). `DISPUTED` — the file
     * exists, so USPS opened a refund request instead, with a `disputeId`, and
     * decides later whether to pay it (documented, not yet observed). Either
     * way the label must not be used, so the package is free to ship again.
     * A reply without a status cannot be read.
     */
    private function readCancelReply(Response $response, string $trackingNumber): CancelResponse
    {
        $payload = $this->decodeJsonSafely($response);
        $status = data_get($payload, 'status');

        if ($status === 'CANCELED') {
            return CancelResponse::success('Label voided successfully.');
        }

        if ($status === 'DISPUTED') {
            $disputeId = data_get($payload, 'disputeId');

            Log::channel('usps-validation')->info('USPS opened a refund request rather than cancelling a label', [
                'tracking_number' => $trackingNumber,
                'dispute_id' => $disputeId,
            ]);

            return CancelResponse::success(
                'Label voided. USPS could no longer cancel it outright, so it opened a refund request'
                .(is_scalar($disputeId) && (string) $disputeId !== '' ? " (dispute {$disputeId})" : '')
                .'. USPS decides whether to refund it.',
            );
        }

        if (is_string($status) && $status !== '') {
            return CancelResponse::failure("USPS did not cancel the label: it answered {$status}.");
        }

        Log::channel('usps-validation')->error('USPS cancelShipment reply could not be read', [
            'status' => $response->status(),
            'tracking_number' => $trackingNumber,
            'body' => $response->body(),
        ]);

        return CancelResponse::unreadable('USPS');
    }

    public function supportsMultiPackage(): bool
    {
        return false;
    }

    public function supportsCarrierManifest(): bool
    {
        return true;
    }

    public function packagingRequirementFor(RateResponse $rate): PackagingRequirement
    {
        return $this->classifyPackaging($rate->metadata);
    }

    /**
     * USPS fuses the declaration into the label: the CP72 comes back as three
     * 4×6 plies inside the one label document — postage on ply 1, plies 2–3
     * stamped "Not Valid As Proof-of-Payment" — in PDF and in ZPL alike. It
     * prints on the thermal path the workstation already has, so a missing
     * report printer must not block it (`shopify-shipping-carrier/23`).
     *
     * Asked of the pair, like {@see createShipment()} deciding whether to
     * send a customs form at all.
     */
    public function customsDocumentDelivery(AddressData $from, AddressData $to, ?RateResponse $rate = null): CustomsDocumentDelivery
    {
        return $from->sharesCustomsZoneWith($to)
            ? CustomsDocumentDelivery::None
            : CustomsDocumentDelivery::FusedIntoLabel;
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

        throw new UnclassifiablePackagingException(Carrier::USPS, "USPS rate indicator {$rateIndicator} under {$mailClass} is not one PolyBag can place in a packaging.");
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
     * only. The presort classes never carry a single-piece indicator. Global
     * Express Guaranteed has never appeared in a response and is absent until
     * it does.
     *
     * Media Mail is single-piece only: across 195 logged sandbox responses
     * (2026-09-08 to 09-21) it came back as `SP` every time, once `MACHINABLE`
     * and once `NONSTANDARD`, at one price. It is sold here, and whether a
     * Package may be offered it is the catalog's question, not this list's:
     * its `CarrierService` requires media contents, and the shared
     * {@see ContentsFilter} drops it for a Package that does not qualify
     * (ADR-0006 decision 10). Library Mail is also a single-piece parcel, but
     * it is deliberately absent: it takes Media Mail's contents plus a
     * condition on sender and recipient that nothing here can vouch for, and
     * it has no catalog service to carry that requirement (decision 11).
     *
     * @var array<string, list<string>>
     */
    private const SHIPPER_PACKAGING_INDICATORS = [
        'USPS_GROUND_ADVANTAGE' => self::DOMESTIC_PARCEL_INDICATORS,
        'PRIORITY_MAIL' => self::DOMESTIC_PARCEL_INDICATORS,
        'PRIORITY_MAIL_EXPRESS' => ['PA'],
        'MEDIA_MAIL' => ['SP'],
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
        // which excludes Library Mail and the presort classes.
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
    private function buildDomesticAddress(AddressData $address, ShipRequest $request): array
    {
        $result = [
            'streetAddress' => mb_substr($this->labelText($address->streetAddress, $request), 0, 50),
            'city' => mb_substr($this->labelText($address->city, $request), 0, 28),
            'state' => $address->stateOrProvince,
            'ZIPCode' => substr($address->postalCode, 0, 5),
        ];

        $this->addNameFields($result, $address, $request);

        if ($address->streetAddress2) {
            $result['secondaryAddress'] = mb_substr($this->labelText($address->streetAddress2, $request), 0, 50);
        }

        return $result;
    }

    /**
     * Build USPS international address array from AddressData DTO.
     *
     * @return array<string, string>
     */
    private function buildInternationalAddress(AddressData $address, ShipRequest $request): array
    {
        $result = [
            'streetAddress' => mb_substr($this->labelText($address->streetAddress, $request), 0, 50),
            'city' => mb_substr($this->labelText($address->city, $request), 0, 30),
            'country' => $address->country,
            'countryISOAlpha2Code' => $address->country,
        ];

        $this->addNameFields($result, $address, $request);

        if ($address->stateOrProvince) {
            $result['province'] = mb_substr($this->labelText($address->stateOrProvince, $request), 0, 30);
        }

        if ($address->postalCode) {
            $result['postalCode'] = mb_substr($address->postalCode, 0, 12);
        }

        if ($address->streetAddress2) {
            $result['secondaryAddress'] = mb_substr($this->labelText($address->streetAddress2, $request), 0, 50);
        }

        return $result;
    }

    /**
     * Address text as USPS can print it on this label
     * (`label-address-characters/03`).
     *
     * A ZPL label gets ASCII, transliterated with `Str::ascii()`. Printed on a
     * Zebra, the domestic template stays in a single-byte code page (`^CI27`
     * or `^CI13`) while the address arrives as UTF-8, so `ë` prints as `Ã?`;
     * the international template switches to UTF-8, but its font has no glyph
     * for `ę` or even `ó`, so each prints as `?`. Domestic text with no ASCII
     * form at all — a name wholly in CJK — is sent as entered rather than
     * blank; an international one never gets here.
     *
     * A PDF prints every character, so a domestic PDF is sent as entered.
     * An international PDF keeps its Latin letters, accents included, but
     * other scripts are romanized: the International Mail Manual (IMM 122)
     * requires the address "with roman letters and arabic numerals".
     *
     * Wherever text is romanized it goes through {@see LabelText::ascii()},
     * which makes digits ASCII first so a house number is not lost.
     */
    private function labelText(string $text, ShipRequest $request): string
    {
        if ($request->labelFormat === 'zpl') {
            $ascii = LabelText::ascii($text);

            return trim($ascii) === '' ? $text : $ascii;
        }

        if ($request->toAddress->country === 'US') {
            return $text;
        }

        return (string) preg_replace_callback(
            self::NON_LATIN_SCRIPT,
            fn (array $match): string => LabelText::ascii($match[0]),
            $text,
        );
    }

    /**
     * Letters and digits in a script other than Latin. ASCII digits,
     * punctuation and spaces are Common, and combining accents Inherited, so
     * neither matches.
     */
    private const NON_LATIN_SCRIPT = '/[^\p{Latin}\p{Common}\p{Inherited}]+/u';

    /**
     * The first address field on an international label that holds a letter
     * or digit with no romanization, named for the operator, or null.
     *
     * `Str::ascii()` drops what its tables do not cover — CJK, Hebrew,
     * Hangul — without a trace (`山田 Taro` becomes ` Taro`). It also drops
     * some letters on purpose: the Cyrillic soft sign has no Latin form, so
     * `Ольга` is `Olga`. Asked to keep what it does not support, the same
     * tables leave only the first kind behind. Modifier letters, such as the
     * Arabic tatweel, are decoration and not counted.
     */
    private function unromanizableAddressField(ShipRequest $request): ?string
    {
        $fields = [
            'firstName' => 'first name',
            'lastName' => 'last name',
            'company' => 'company',
            'streetAddress' => 'street address',
            'streetAddress2' => 'second address line',
            'city' => 'city',
            'stateOrProvince' => 'state or province',
        ];

        foreach (['recipient' => $request->toAddress, 'sender' => $request->fromAddress] as $party => $address) {
            foreach ($fields as $property => $label) {
                $unsupported = ASCII::to_ascii(LabelText::asciiDigits((string) $address->{$property}), remove_unsupported_chars: false);

                if (preg_match('/(?![\x00-\x7F])[\p{Lu}\p{Ll}\p{Lt}\p{Lo}\p{Nd}]/u', $unsupported)) {
                    return "{$party}'s {$label}";
                }
            }
        }

        return null;
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
    private function addNameFields(array &$result, AddressData $address, ShipRequest $request): void
    {
        $hasFirst = (bool) $address->firstName;
        $hasLast = (bool) $address->lastName;

        if ($hasFirst && $hasLast) {
            $result['firstName'] = mb_substr($this->labelText($address->firstName, $request), 0, 30);
            $result['lastName'] = mb_substr($this->labelText($address->lastName, $request), 0, 30);
        } elseif ($hasFirst || $hasLast) {
            // Only one name — use firm field so USPS doesn't reject it
            $name = $hasFirst ? $address->firstName : $address->lastName;
            $result['firm'] = mb_substr($this->labelText($name, $request), 0, 38);
        }

        if ($address->company) {
            $result['firm'] = mb_substr($this->labelText($address->company, $request), 0, 38);
        }
    }
}
