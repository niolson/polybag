<?php

namespace App\Services\Carriers;

use App\Contracts\AsyncRateQuoting;
use App\Contracts\RecoversUnresolvedPurchase;
use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\PostageSources\OfferDraft;
use App\DataTransferObjects\PostageSources\ServiceObservation;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\AmazonPurchasedLabel;
use App\DataTransferObjects\Shipping\AmazonShippingQuote;
use App\DataTransferObjects\Shipping\PackageData;
use App\DataTransferObjects\Shipping\PackagingRequirement;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\AmazonChannelType;
use App\Enums\CarrierPackaging;
use App\Enums\CustomsDocumentDelivery;
use App\Enums\OffAmazonShippingStatus;
use App\Enums\PostageSource;
use App\Enums\ServiceCapability;
use App\Enums\ServiceEvidence;
use App\Enums\SourceEnvironment;
use App\Exceptions\Carriers\AmazonLabelPurchaseException;
use App\Exceptions\Carriers\CarrierRateFetchException;
use App\Exceptions\Carriers\CarrierUnavailableException;
use App\Exceptions\MissingAmazonOrderItemsException;
use App\Http\Integrations\Amazon\AmazonSpApiConnector;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Models\DataSource;
use App\Models\ObservedService;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Services\AmazonBuyShippingService;
use App\Services\PostageSources\ObservedServiceRecorder;
use App\Services\PostageSources\OffAmazonShippingCheck;
use App\Services\PostageSources\OfferStore;
use App\Services\RateSelector;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\Shipping\PackagingFilter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;

/**
 * Buys postage through Amazon Shipping v2: Buy Shipping for an Amazon order,
 * and Amazon Shipping for an order from another channel.
 *
 * Amazon is not a carrier, and unlike Shopify it does not pretend to be one
 * either: a single `getRates` for one parcel came back with OnTrac, UPS and
 * USPS priced independently, against a catalog of 102 more it declined
 * (`amazon-buy-shipping/01`). So each rate keeps the carrier Amazon named for
 * *that offer* — which is the whole reason quoting and purchasing had to be
 * split off carrier-name dispatch first (`postage-source-split/08`). A rate
 * carried by OnTrac says OnTrac; buying it still calls Amazon.
 *
 * An order from another channel is rated `channelType: EXTERNAL` on the Amazon
 * connection scoped to sell it (`amazon-shipping-external-orders/05`). Its
 * rates go through the same filters, observations and offers as on-Amazon
 * ones, bound to that connection. An account Amazon has not set up for it
 * answers `403 A-101`, which {@see parseRateResponse()} reports as the
 * connection being unavailable rather than as an Amazon error.
 *
 * Three consequences worth stating plainly, because each is a decision:
 *
 * - **Every rate carries an {@see ObservedServiceIdentity}.** Amazon's catalog
 *   is discovered, not authored, and that identity is the only thing that tells
 *   {@see RateSelector::selectBest()} it is looking at a discovered service. A
 *   rate that arrived without one would read as seeded configuration and could
 *   win an unattended purchase nobody approved (ADR-0003 decision 4). It is
 *   built from the same four values handed to {@see ObservedServiceRecorder},
 *   so the gate and the store cannot disagree about what was seen.
 * - **Every rate carries a {@see ShippingOffer}.** `rateId` and `requestToken`
 *   are opaque and expire, so the price a packer clicked is only buyable
 *   through the row that holds them (ADR-0002 decision 4).
 * - **`$serviceCodes` is ignored.** `getRates` is one call that returns
 *   whatever the order is eligible for; there is nothing to filter *before*.
 *   Filtering after is what `amazon-buy-shipping/08` settled on: the
 *   constraint travels on the Package as its carrier packaging (ADR-0005),
 *   and {@see isBuyable()} applies it before an offer is issued.
 */
class AmazonBuyShippingAdapter implements AsyncRateQuoting, RecoversUnresolvedPurchase
{
    /** The name this source is registered and displayed under. */
    public const SOURCE_NAME = 'Amazon';

    /**
     * The key observations and approvals are filed under.
     *
     * Lower case, matching {@see AmazonSource::getDestinationName()} and the
     * `observed_services.source` column, which is not the same string as the
     * registry name above — one is an identifier, the other is a label.
     */
    public const OBSERVATION_SOURCE = 'amazon';

    /**
     * The one seeded `CarrierService` under the `Amazon` carrier.
     *
     * Not a service. It is how a shipping method says "ask Amazon", in a
     * catalog whose rows are otherwise authored per service — Amazon's own
     * catalog is discovered per order and nothing here may mint rows from it.
     * The adapter never reads it back: `getRates` takes no service filter, so
     * the code's only job is to exist.
     */
    public const CATALOG_SERVICE_CODE = 'AMAZON_BUY_SHIPPING';

    /** Where a Buy Shipping purchase records what Amazon called it. */
    public const SHIPMENT_ID_KEY = 'amazon_shipment_id';

    public const CARRIER_ID_KEY = 'amazon_carrier_id';

    public const SERVICE_ID_KEY = 'amazon_service_id';

    /**
     * The `rate_metadata` key under which a rate says whether buying it
     * returns a customs document beside the label — the Amazon half of the
     * pre-purchase gate `shopify-shipping-carrier/07` describes. Stamped at
     * quote time and re-derived at purchase by {@see returnsSeparateCustomsDocument()},
     * from the offering's own document details rather than from the address.
     */
    public const CUSTOMS_DOCUMENT_METADATA_KEY = 'returnsSeparateCustomsDocument';

    /**
     * How long a fetched additional-inputs schema is remembered per `rateId`,
     * so that a reply parsed twice asks Amazon once. Longer than the offer
     * window: a `rateId` is never reissued, so nothing is lost by keeping it.
     */
    private const ADDITIONAL_INPUTS_SCHEMA_CACHE_SECONDS = 900;

    /**
     * Amazon's confirmation value-added services, and the special-service codes
     * they answer. Anything absent is `NotImplemented`: the request proceeds
     * without it rather than pretending.
     *
     * @var array<string, string>
     */
    private const SUPPORTED_SERVICES = [
        'signature_required' => 'SIGNATURE_CONFIRMATION',
        'adult_signature_required' => 'ADULT_SIGNATURE_CONFIRMATION',
        'declared_value' => 'DECLARED_VALUE',
    ];

    /**
     * Services valid only for specific contents — Media Mail and Bound Printed
     * Matter — which nothing on a Package can yet vouch for. Misdeclared Media
     * Mail is a postal offence, so these are dropped outright by
     * {@see carriesPermittedContent()}.
     *
     * @var list<string>
     */
    private const CONTENT_RESTRICTED_SERVICES = [
        'USPS_PTP_MM',
        'USPS_PTP_BPM',
        'UPS_PTP_SUREPOST_MEDIA',
        'UPS_PTP_SUREPOST_BPM',
    ];

    public function getCarrierName(): string
    {
        return self::SOURCE_NAME;
    }

    /**
     * Configured when any active Amazon data source exists. Whether one can
     * rate a given package — its own Amazon order, or a scope for an order from
     * another channel — is settled per package by
     * {@see AmazonBuyShippingService::quotingSourceFor()}.
     */
    public function isConfigured(): bool
    {
        return DataSource::query()
            ->where('active', true)
            ->where('source_type', AmazonSource::class)
            ->exists();
    }

    /**
     * What Amazon can promise, asked before any rate exists.
     *
     * The honest answer at this point is per-carrier-set rather than per-offer:
     * `availableValueAddedServiceGroups` varies by rate, and `01` found the
     * Confirmation group on every UPS and USPS offer and on none of OnTrac's.
     * Saying `Supported` here and then dropping the individual rates that
     * cannot honour a hard requirement — see {@see honoursRequiredServices()} — keeps the
     * judgement at the offer seam where ADR-0002 decision 8 puts it, instead of
     * excluding Amazon wholesale for a service most of its offers do carry.
     */
    public function offerCapability(string $serviceCode): ServiceCapability
    {
        return array_key_exists($serviceCode, self::SUPPORTED_SERVICES)
            ? ServiceCapability::Supported
            : ServiceCapability::NotImplemented;
    }

    /**
     * No cap of our own to report. The carrier behind the offer has one, and
     * Amazon prices the declared value into the rate rather than publishing a
     * ceiling we could compare against before quoting.
     */
    public function offerDeclaredValueCap(): ?float
    {
        return null;
    }

    /**
     * @param  array<string>  $serviceCodes
     */
    public function prepareRateRequest(RateRequest $request, array $serviceCodes): ?PreparedRateRequest
    {
        [$source, $rateRequest] = $this->rateRequestFor($request) ?? [null, null];

        if (! $source || ! $rateRequest) {
            return null;
        }

        return new PreparedRateRequest(
            pendingRequest: app(AmazonBuyShippingService::class)->connectorFor($source)->createPendingRequest($rateRequest),
            carrierName: self::SOURCE_NAME,
        );
    }

    /**
     * The connection to ask and the `getRates` to send it, or null when this
     * package has no Amazon offer.
     *
     * @return array{0: DataSource, 1: GetShippingRates}|null
     */
    private function rateRequestFor(RateRequest $request): ?array
    {
        $package = $this->packageFor($request);
        $service = app(AmazonBuyShippingService::class);

        if (! $package || $request->packages === []) {
            return null;
        }

        $source = $service->quotingSourceFor($package);

        if (! $source) {
            return null;
        }

        try {
            $payload = $service->ratePayloadFor($package, $request);
        } catch (MissingAmazonOrderItemsException $e) {
            // A parcel Amazon cannot be told the contents of is not an error to
            // put in front of a packer — it simply has no Amazon offer, and the
            // direct carriers still quote.
            logger()->info('Skipped an Amazon quote for a package Amazon cannot identify', [
                'package_id' => $package->id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $rateRequest = new GetShippingRates($payload, AmazonBuyShippingService::BUSINESS_ID);

        // A refusal is an answer this adapter reads — `403 A-101` says the
        // connection is not set up for off-Amazon shipping — so it has to reach
        // parseRateResponse() as a response. Left to Guzzle, the concurrent
        // path would turn it into a rejected promise nobody here sees.
        $rateRequest->config()->add('http_errors', false);

        return [$source, $rateRequest];
    }

    /**
     * @param  array<string>  $serviceCodes
     * @return Collection<int, RateResponse>
     *
     * @throws CarrierUnavailableException when the connection selling off-Amazon Amazon Shipping is not set up for it
     */
    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
    {
        $package = $this->packageFor($request);

        if (! $package) {
            return collect();
        }

        // Read off the request that was sent, never resolved again: a scope
        // edited or a connection switched off while the request was in flight
        // must not move this reply, its offers or its A-101 onto another
        // connection (ADR-0002 decision 4).
        $source = $this->connectionThatSent($response);
        $offAmazon = $this->wasSentOffAmazon($response);

        if (! $source) {
            logger()->warning('Amazon getRates reply names no connection it was sent for', [
                'package_id' => $package->id,
                'status' => $response->status(),
            ]);

            return collect();
        }

        if (! $response->successful()) {
            if ($offAmazon && OffAmazonShippingCheck::refusesAccount($response)) {
                $this->recordOffAmazonShippingStatus($source, OffAmazonShippingStatus::NotSetUp);

                throw new CarrierUnavailableException(
                    self::SOURCE_NAME,
                    "The Amazon connection \"{$source->name}\" is not set up to sell Amazon Shipping for orders from other channels, so it has no offers for this package. "
                    .OffAmazonShippingCheck::NOT_SET_UP_MESSAGE,
                );
            }

            logger()->warning('Amazon getRates failed', [
                'package_id' => $package->id,
                'status' => $response->status(),
                'channel' => ($offAmazon ? AmazonChannelType::External : AmazonChannelType::Amazon)->apiValue(),
                'errors' => $response->json('errors'),
            ]);

            return collect();
        }

        if ($offAmazon) {
            $this->recordOffAmazonShippingStatus($source, OffAmazonShippingStatus::Enabled);
        }

        return $this->ratesFrom(
            AmazonShippingQuote::fromPayload($response->json('payload', [])),
            $package,
            $request,
            $source,
            $offAmazon ? AmazonChannelType::External : AmazonChannelType::Amazon,
        );
    }

    /**
     * @param  array<string>  $serviceCodes
     * @return Collection<int, RateResponse>
     *
     * @throws CarrierUnavailableException when the connection selling off-Amazon Amazon Shipping is not set up for it
     */
    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        [$source, $rateRequest] = $this->rateRequestFor($request) ?? [null, null];

        if (! $source || ! $rateRequest) {
            return collect();
        }

        try {
            $response = app(AmazonBuyShippingService::class)->connectorFor($source)->send($rateRequest);
        } catch (RequestException $e) {
            // The connector retries, and throws once it gives up. What it
            // gave up on is still Amazon's answer, and read the same way.
            $response = $e->getResponse();
        } catch (FatalRequestException $e) {
            throw new CarrierRateFetchException(self::SOURCE_NAME, $e);
        }

        return $this->parseRateResponse($response, $request, $serviceCodes);
    }

    /**
     * The connection whose credentials sent this request — the one that was
     * resolved when it was prepared, loaded even if it has since been switched
     * off, because the reply is still that account's answer. The purchase
     * refuses an inactive connection on its own.
     */
    private function connectionThatSent(Response $response): ?DataSource
    {
        $connector = $response->getConnector();
        $id = $connector instanceof AmazonSpApiConnector ? $connector->dataSourceId() : null;

        return $id === null ? null : DataSource::find($id);
    }

    private function wasSentOffAmazon(Response $response): bool
    {
        $request = $response->getRequest();

        return $request instanceof GetShippingRates && $request->channelType() === AmazonChannelType::External->apiValue();
    }

    /**
     * Keep the connection's off-Amazon answer current from what a quote found.
     *
     * A production quote is the same question the connection's check asks, so
     * its answer is recorded the same way: `403 A-101` means not set up, and a
     * `200` means enabled — which also clears a stale "not set up" once the
     * seller finishes sign-up. The sandbox quotes every account, so a sandbox
     * answer proves nothing and is not recorded, as the check does not ask.
     */
    private function recordOffAmazonShippingStatus(DataSource $source, OffAmazonShippingStatus $status): void
    {
        if (SourceEnvironment::current() !== SourceEnvironment::Production
            || $source->off_amazon_shipping_status === $status) {
            return;
        }

        $source->forceFill([
            'off_amazon_shipping_status' => $status,
            'off_amazon_shipping_checked_at' => now(),
        ])->save();
    }

    /**
     * Nothing to resolve: an Amazon rate already *is* a specific offer, priced
     * and tokenized. The variant-picking `UspsAdapter` does here has no analogue
     * — there is no second call that would narrow one Amazon `rateId` into
     * another. Only the packaging filter stands between the rule and the buy.
     */
    public function resolvePreSelectedRate(RateResponse $rate, Package $package): ?RateResponse
    {
        return PackagingFilter::keepCompatible(collect([$rate]), PackageData::fromPackage($package)->carrierPackaging)->first();
    }

    /**
     * Classifies from the `amazonServiceId` in the rate metadata, which the
     * purchase path rebuilds from the stored offer rather than from the
     * browser — so the packaging checked at purchase is the packaging quoted.
     */
    public function packagingRequirementFor(RateResponse $rate): PackagingRequirement
    {
        return $this->classifyPackaging((string) ($rate->metadata['amazonServiceId'] ?? ''));
    }

    /**
     * From the offering when there is one: {@see returnsSeparateCustomsDocument()}
     * reads the `CUSTOM_FORM` declaration off the stored rate, which is what
     * lifts the territory over-block — Amazon quotes Puerto Rico as plain
     * domestic USPS, and that offering declares no form.
     *
     * Without a rate (batch validation, before anything is quoted) the
     * offering cannot be read, so the answer is the lane's: a foreign country
     * gets Amazon's stated separate `CUSTOM_FORM`, and a territory gets the
     * plain domestic label `09` observed four times. An approximation of the
     * offering, corrected by the purchase-time check, which always has one.
     */
    public function customsDocumentDelivery(AddressData $from, AddressData $to, ?RateResponse $rate = null): CustomsDocumentDelivery
    {
        if ($rate !== null) {
            return $this->returnsSeparateCustomsDocument($rate)
                ? CustomsDocumentDelivery::Separate
                : CustomsDocumentDelivery::None;
        }

        return $from->country !== $to->country
            ? CustomsDocumentDelivery::Separate
            : CustomsDocumentDelivery::None;
    }

    /**
     * Whether buying this rate returns a customs document beside the label,
     * which the report printer has to be there for.
     *
     * Re-derived from the `supportedDocumentSpecifications` the offer stored,
     * the same way {@see packagingRequirementFor()} re-classifies — so the
     * purchase reads the offering the quote read, never the browser's copy of
     * the stamp. See {@see AmazonBuyShippingService::declaresCustomsForm()}
     * for why the offering and not the address.
     */
    public function returnsSeparateCustomsDocument(RateResponse $rate): bool
    {
        return AmazonBuyShippingService::declaresCustomsForm(
            (array) ($rate->metadata['supportedDocumentSpecifications'] ?? [])
        );
    }

    /**
     * Which packaging an Amazon serviceId is valid in — ADR-0005 decision 5.
     *
     * Read off the serviceId because that is all Amazon gives: `getRates` is
     * handed dimensions and a weight and rates every service those fit, so a
     * 4x6x6 box was offered thirteen services valid only in the carrier's own
     * packaging (`amazon-buy-shipping/12`, whose drop list this is). The direct
     * adapters never show these — {@see UspsAdapter} picks the flat-rate
     * indicator from the box size, {@see FedexAdapter} stamps the packaging it
     * sent — and this is the Amazon equivalent.
     *
     * Tokens match whole path segments, so `_INTL` and `_CUSTOMS` variants
     * classify to the same packaging and `USPS_PTP_FC` matches nothing:
     *
     * - A flat-rate envelope token (`_FRE`, `_LFRE`, `_PFRE`) is `exactly()`
     *   the envelope of that shape for the mail class named beside it. A
     *   Priority Mail Express envelope is separate stock from a Priority Mail
     *   one, and a packer uses the service printed on the envelope, so
     *   `USPS_PTP_EXP_FRE` and `USPS_PTP_PRI_FRE` are different packagings. A
     *   serviceId carrying the token under a mail class not seen yet is
     *   `anyOf()` both, which still admits nothing the packer supplied.
     * - A flat-rate box token (`_SFRB`, `_MFRB`, `_LFRB`) is `exactly()` that
     *   box. Priority Mail only; Express has none.
     * - FedEx One Rate (`_ONE_RATE`) is `anyOf()` every FedEx packaging: the
     *   serviceId says FedEx-supplied and never which, and FedEx prices One
     *   Rate by service rather than by container.
     * - Anything else is the packer's own packaging. A discovered catalog
     *   cannot be allowlisted (ADR-0003), so an unrecognized serviceId is a
     *   shipper-packaging rate, not a dropped one.
     */
    private function classifyPackaging(string $serviceId): PackagingRequirement
    {
        if (preg_match('/_(?<shape>[LP]?)FRE(?:_|$)/', $serviceId, $token) === 1) {
            return $this->flatRateEnvelopeRequirement($serviceId, $token['shape']);
        }

        if (preg_match('/_(?<size>[SML])FRB(?:_|$)/', $serviceId, $token) === 1) {
            return PackagingRequirement::exactly(match ($token['size']) {
                'S' => CarrierPackaging::UspsSmallFlatRateBox,
                'M' => CarrierPackaging::UspsMediumFlatRateBox,
                'L' => CarrierPackaging::UspsLargeFlatRateBox,
            });
        }

        if (preg_match('/_ONE_RATE(?:_|$)/', $serviceId) === 1) {
            return PackagingRequirement::anyOf(...array_filter(
                CarrierPackaging::cases(),
                fn (CarrierPackaging $packaging): bool => $packaging->carrier() === 'FedEx',
            ));
        }

        return PackagingRequirement::shipperPackaging();
    }

    /**
     * The envelope a flat-rate envelope serviceId names, by shape and mail class.
     *
     * @param  string  $shape  `''` plain, `L` legal, `P` padded — the letter before `FRE`.
     */
    private function flatRateEnvelopeRequirement(string $serviceId, string $shape): PackagingRequirement
    {
        $segments = explode('_', $serviceId);

        $priority = match ($shape) {
            'L' => CarrierPackaging::UspsLegalFlatRateEnvelope,
            'P' => CarrierPackaging::UspsPaddedFlatRateEnvelope,
            default => CarrierPackaging::UspsFlatRateEnvelope,
        };
        $express = match ($shape) {
            'L' => CarrierPackaging::UspsExpressLegalFlatRateEnvelope,
            'P' => CarrierPackaging::UspsExpressPaddedFlatRateEnvelope,
            default => CarrierPackaging::UspsExpressFlatRateEnvelope,
        };

        if (in_array('EXP', $segments, true)) {
            return PackagingRequirement::exactly($express);
        }

        if (in_array('PRI', $segments, true)) {
            return PackagingRequirement::exactly($priority);
        }

        return PackagingRequirement::anyOf($priority, $express);
    }

    public function createShipment(ShipRequest $request): ShipResponse
    {
        $package = $this->purchasablePackage($request);

        if (! $package) {
            return ShipResponse::failure(
                'Amazon Buy Shipping labels are bought against a quoted offer for a saved package, which this '
                .'request did not carry. Get rates again and choose one.'
            );
        }

        try {
            $label = app(AmazonBuyShippingService::class)->purchase($package, $request->offer, $request);
        } catch (AmazonLabelPurchaseException $e) {
            logger()->error('Amazon Buy Shipping purchase failed', [
                'package_id' => $package->id,
                'offer' => $request->offer->public_id,
                'error' => $e->getMessage(),
            ]);

            return ShipResponse::failure($e->getMessage());
        }

        return $this->shipResponse($label, $request, $package);
    }

    /**
     * Ask Amazon whether a spent offer actually bought anything.
     *
     * The same call as {@see createShipment()}, deliberately: `purchaseShipment`
     * carries the offer's identifier as `x-amzn-IdempotencyKey`, so a repeat is
     * recognized as the same purchase and answered with the shipment Amazon
     * already made. That is what makes asking safe, and it is the only reason
     * this contract can be implemented here at all.
     *
     * **Only success resolves the offer.** Every other outcome returns null and
     * leaves the package blocked, including `TOKEN_EXPIRED`: an expired token
     * is consistent both with a purchase that never happened *and* with one
     * that did, if Amazon validates the token before replaying the key. The two
     * mistakes are not symmetrical — guessing wrong costs a second label —
     * so the ambiguous answer stays ambiguous and a person decides.
     */
    public function recoverPurchase(ShipRequest $request): ?ShipResponse
    {
        $package = $this->purchasablePackage($request);

        if (! $package) {
            return null;
        }

        try {
            $label = app(AmazonBuyShippingService::class)->purchase($package, $request->offer, $request);
        } catch (\Throwable $e) {
            logger()->warning('Could not establish what became of an Amazon purchase', [
                'package_id' => $package->id,
                'offer' => $request->offer->public_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->shipResponse($label, $request, $package);
    }

    /**
     * The package this request may buy postage for, or null.
     *
     * Amazon postage is bought with a `rateId` and a `requestToken` that exist
     * nowhere but the offer, so a request without one came from a path that
     * still thinks a carrier name is enough to dispatch on.
     */
    private function purchasablePackage(ShipRequest $request): ?Package
    {
        if (! $request->packageId
            || ! $request->offer
            || $request->offer->postage_source !== PostageSource::PostageDataSource) {
            return null;
        }

        return Package::with('shipment')->find($request->packageId);
    }

    /**
     * What the package records about a label Amazon sold.
     *
     * A shipment with no tracking number is still a shipment that was paid for,
     * so this reports a failure rather than throwing — and the offer is already
     * stamped with Amazon's shipment ID by then, which is what stops that
     * failure being read as "nothing was bought".
     */
    private function shipResponse(AmazonPurchasedLabel $label, ShipRequest $request, Package $package): ShipResponse
    {
        $offer = $request->offer;

        if (! $label->trackingId) {
            return ShipResponse::failure(
                "Amazon bought shipment {$label->shipmentId} but returned no tracking number. "
                .'Check the order in Seller Central before buying again.'
            );
        }

        $metadata = $offer->rate_metadata ?? [];

        return new ShipResponse(
            success: true,
            trackingNumber: $label->trackingId,
            cost: $offer->price === null ? null : (float) $offer->price,
            // The carrier of record is whoever is carrying the parcel, which is
            // never "Amazon" — Amazon is where the postage was bought, and that
            // is the postage source below. ADR-0002's whole split, in one field.
            carrier: $offer->carrier,
            service: $offer->service_name,
            // Amazon sold exactly the rate that was chosen by ID, so the service
            // is reported rather than guessed.
            serviceEvidence: ServiceEvidence::Confirmed,
            labelData: $label->labelData,
            labelOrientation: 'portrait',
            labelFormat: $label->labelFormat,
            labelDpi: $label->labelDpi,
            customsFormData: $label->customsFormData,
            customsFormFormat: $label->customsFormFormat ?? 'pdf',
            shipDate: $request->shipDate,
            appliedServices: $this->appliedServices($metadata, $request),
            postageSource: PostageSource::PostageDataSource,
            postageDataSourceId: $offer->postage_data_source_id
                ?? app(AmazonBuyShippingService::class)->postageSourceFor($package)?->id,
            sourceLabelReference: $label->shipmentId,
            metadata: array_filter([
                self::SHIPMENT_ID_KEY => $label->shipmentId,
                // Amazon's own carrier identifier, not our `Carrier` row's name.
                // `getTracking` takes it, and a courier we hold no row for has
                // no other handle.
                self::CARRIER_ID_KEY => $metadata['amazonCarrierId'] ?? null,
                self::SERVICE_ID_KEY => $metadata['amazonServiceId'] ?? null,
            ], fn (?string $value): bool => filled($value)),
        );
    }

    /**
     * Amazon's shipment ID for a package, or null when Amazon did not sell it.
     *
     * Static because the channel export reads it to decide whether the order is
     * already confirmed, and that path holds no adapter and wants no registry.
     */
    public static function shipmentIdFor(Package $package): ?string
    {
        $shipmentId = $package->metadata[self::SHIPMENT_ID_KEY] ?? null;

        return filled($shipmentId) ? (string) $shipmentId : null;
    }

    /**
     * Turn one `getRates` reply into rates, offers and observations.
     *
     * The order matters. Observations are recorded first, for the eligible and
     * the ineligible alike, because the durable catalog is the point of reading
     * the reply at all and it must not depend on a rate surviving the filters
     * below. Offers are issued only for rates that survive them: an offer for a
     * rate nobody can be shown is a row that can only ever expire.
     *
     * Every offer is bound to `$source`, the connection that was asked — for
     * an off-Amazon quote the scoped connection, never the Shipment's import
     * source. Every rate carries the channel type it was quoted for, because
     * an approval for Amazon orders does not cover the same service sold for
     * an order from another channel (`amazon-shipping-external-orders/07`).
     *
     * @return Collection<int, RateResponse>
     */
    private function ratesFrom(AmazonShippingQuote $quote, Package $package, RateRequest $request, DataSource $source, AmazonChannelType $channelType): Collection
    {
        $marketplace = app(AmazonBuyShippingService::class)->marketplaceIdFor($source);

        $observations = $this->record($quote, $marketplace);
        $this->recordAdditionalInputsSchemas($quote, $source, $observations);

        $environment = SourceEnvironment::current();
        $expiresAt = now()->addSeconds(AmazonBuyShippingService::OFFER_WINDOW_SECONDS);
        $offerStore = app(OfferStore::class);

        return collect($quote->rates)
            ->filter(fn (array $rate): bool => $this->isBuyable($rate, $request))
            ->map(function (array $rate) use (
                $package, $observations, $environment, $expiresAt, $offerStore, $quote, $source, $marketplace, $channelType
            ): RateResponse {
                $carrierId = (string) $rate['carrierId'];
                $serviceId = (string) $rate['serviceId'];
                $mapped = $observations->get(ObservedService::serviceKey(self::OBSERVATION_SOURCE, $carrierId, $serviceId))
                    ?->carrierService;

                $carrier = $mapped?->carrier->name ?? (string) ($rate['carrierName'] ?? $carrierId);
                $serviceName = $mapped->name ?? (string) ($rate['serviceName'] ?? $serviceId);
                $price = round((float) ($rate['totalCharge']['value'] ?? 0), 2);
                $currency = (string) ($rate['totalCharge']['unit'] ?? 'USD');

                // Stored with the offer so the purchase re-classifies from
                // server-side data, never from the browser.
                $packagingRequirement = $this->classifyPackaging($serviceId);

                $offer = $offerStore->issue($package, new OfferDraft(
                    carrier: $carrier,
                    postageSource: PostageSource::PostageDataSource,
                    postageDataSourceId: $source->id,
                    // The service code an authored mapping gives it, so a
                    // shipping rule written against Ground Advantage matches
                    // whichever source quoted it. Unmapped, Amazon's own
                    // identifier stands in — it is what the service *is*.
                    serviceCode: $mapped->service_code ?? $serviceId,
                    serviceName: $serviceName,
                    price: $price,
                    currency: $currency,
                    rateMetadata: $this->rateMetadata($rate, $packagingRequirement),
                    purchaseContext: [
                        'requestToken' => $quote->requestToken,
                        'rateId' => (string) $rate['rateId'],
                    ],
                    expiresAt: $expiresAt,
                    marketplace: $marketplace,
                    // No quote fingerprint here: the request in hand is the
                    // per-carrier one, with codes Amazon cannot express
                    // dropped, and would not match the package-level request
                    // the offer store recomputes. `ShippingRateService`
                    // stamps the shared one when it points this row at its
                    // quote log.
                ));

                return new RateResponse(
                    carrier: $carrier,
                    serviceCode: $offer->service_code ?? $serviceId,
                    serviceName: $serviceName,
                    price: $price,
                    deliveryDate: $rate['promise']['deliveryWindow']['end'] ?? null,
                    metadata: $offer->rate_metadata ?? [],
                    offerId: $offer->public_id,
                    observedService: new ObservedServiceIdentity(
                        source: self::OBSERVATION_SOURCE,
                        environment: $environment,
                        channelType: $channelType,
                        externalCarrierId: $carrierId,
                        externalServiceId: $serviceId,
                    ),
                    packagingRequirement: $packagingRequirement,
                );
            })
            ->values();
    }

    /**
     * Record every identity the reply named, eligible or not.
     *
     * The ineligible array is the larger half and the more valuable one — 102
     * services across fourteen carriers in the production run, against six
     * eligible. Its reason codes are `UNKNOWN` on every entry, so only identity
     * is taken from it; what it buys is a catalog to map and approve *before* a
     * parcel that qualifies turns up.
     *
     * @return Collection<string, ObservedService> keyed by service key
     */
    private function record(AmazonShippingQuote $quote, ?string $marketplace): Collection
    {
        $observation = fn (array $rate, bool $eligible): ServiceObservation => new ServiceObservation(
            source: self::OBSERVATION_SOURCE,
            externalCarrierId: (string) ($rate['carrierId'] ?? ''),
            externalServiceId: (string) ($rate['serviceId'] ?? ''),
            externalCarrierName: $rate['carrierName'] ?? null,
            externalServiceName: $rate['serviceName'] ?? null,
            marketplace: $marketplace,
            eligible: $eligible,
        );

        $observations = collect($quote->rates)
            ->map(fn (array $rate): ServiceObservation => $observation($rate, true))
            ->merge(collect($quote->ineligibleRates)
                ->map(fn (array $rate): ServiceObservation => $observation($rate, false)))
            ->filter(fn (ServiceObservation $o): bool => $o->externalCarrierId !== '' && $o->externalServiceId !== '');

        // The recorder hands back plain rows; the mapping and its carrier are
        // what turn an identity into a name, and they are loaded once here
        // rather than lazily per rate.
        return EloquentCollection::make(app(ObservedServiceRecorder::class)->record($observations))
            ->load('carrierService.carrier')
            ->keyBy(fn (ObservedService $service): string => ObservedService::serviceKey(
                $service->source,
                $service->external_carrier_id,
                $service->external_service_id,
            ));
    }

    /**
     * Fetch and keep the schema for every rate that asks for additional inputs.
     *
     * This is what turns "wait for a test order" into "wait for a customer"
     * (`13`): the first cross-border offer any tenant is quoted answers `09`'s
     * first finding by itself, without anyone buying a label. The schema
     * carries no order data, so it goes on the observation row for the
     * service — the durable place, beside the identity it belongs to — and is
     * logged at `info` for whoever is watching. Fetching is free and touches
     * nothing; a failure to fetch changes nothing about the quote, because the
     * rate is dropped either way.
     *
     * Once per distinct `rateId`, never per page render: a fetched ID is
     * remembered for longer than the offer lives, so a reply parsed twice asks
     * once — and a failed fetch is forgotten, so the next parse asks again.
     *
     * @param  Collection<string, ObservedService>  $observations  keyed by service key
     */
    private function recordAdditionalInputsSchemas(AmazonShippingQuote $quote, DataSource $source, Collection $observations): void
    {
        $service = app(AmazonBuyShippingService::class);

        foreach ($quote->rates as $rate) {
            if (! ($rate['requiresAdditionalInputs'] ?? false) || blank($rate['rateId'] ?? null)) {
                continue;
            }

            $rateId = (string) $rate['rateId'];

            if (! Cache::add("amazon-additional-inputs-schema:{$rateId}", true, self::ADDITIONAL_INPUTS_SCHEMA_CACHE_SECONDS)) {
                continue;
            }

            $schema = $service->additionalInputsSchema($source, $quote->requestToken, $rateId);

            if ($schema === null) {
                // Only a fetched schema is remembered. A failed fetch must not
                // suppress the next attempt for a rate that may be the only
                // one of its kind this account is quoted before it expires.
                Cache::forget("amazon-additional-inputs-schema:{$rateId}");

                continue;
            }

            $carrierId = (string) ($rate['carrierId'] ?? '');
            $serviceId = (string) ($rate['serviceId'] ?? '');

            logger()->info('Amazon rate requires additional inputs', [
                'carrier_id' => $carrierId,
                'service_id' => $serviceId,
                'schema' => $schema,
            ]);

            $observations->get(ObservedService::serviceKey(self::OBSERVATION_SOURCE, $carrierId, $serviceId))
                ?->forceFill([
                    'additional_inputs_schema' => $schema,
                    'additional_inputs_schema_seen_at' => now(),
                ])
                ->save();
        }
    }

    /**
     * Whether this offer could actually be bought and printed.
     *
     * Four filters that would otherwise fail the *purchase* rather than the
     * quote — after the packer has committed and, for the second one, after
     * the offer has been spent — and two that would fail at the carrier's
     * acceptance counter, after the label is on the parcel.
     *
     * The packaging predicate is the shared one, {@see PackagingRequirement::accepts()},
     * which {@see ShippingRateService} runs again over everything it collects.
     * It is applied here as well, one step earlier, because this adapter has a
     * side effect at this step: {@see ratesFrom()} issues a {@see ShippingOffer}
     * — purchase authority, ADR-0002 decision 4 — for every rate it returns,
     * and a rate the shared filter would later hide must never hold one
     * (ADR-0005 decision 5). Thirteen of the live run's thirty-five offers
     * would otherwise have been rows for rates nobody was shown.
     */
    private function isBuyable(array $rate, RateRequest $request): bool
    {
        return $this->hasPrintableDocument($rate)
            && $this->needsNoAdditionalInputs($rate)
            && $this->honoursRequiredServices($rate, $request)
            && $this->answersRequiredGroupsForFree($rate, $request)
            && $this->fitsThePackaging($rate, $request)
            && $this->carriesPermittedContent($rate);
    }

    /**
     * Drop a rate whose purchase must carry `additionalInputs`.
     *
     * Nothing here can build them yet: the schema they must satisfy is
     * fetched per rate and has never been seen for this account (`09`), so a
     * rate that asks for them would pass the quote and fail the purchase. The
     * same shape as {@see hasPrintableDocument()} — refused before an offer is
     * issued and before the money. The observation is still recorded, and
     * {@see recordAdditionalInputsSchemas()} keeps what the rate asked for, so
     * the drop becomes "satisfied or dropped" once someone has read a schema.
     */
    private function needsNoAdditionalInputs(array $rate): bool
    {
        return ! ($rate['requiresAdditionalInputs'] ?? false);
    }

    /**
     * A rate offering none of the formats the print path can send to QZ Tray
     * — ZPL raw, PNG and PDF through the pixel path — is unbuyable and is
     * dropped here rather than at purchase time.
     */
    private function hasPrintableDocument(array $rate): bool
    {
        return collect($rate['supportedDocumentSpecifications'] ?? [])
            ->contains(fn (array $spec): bool => in_array($spec['format'] ?? null, ['PDF', 'ZPL', 'PNG'], true));
    }

    /**
     * Drop an offer that cannot honour a service the shipment hard-requires.
     *
     * This is ADR-0002 decision 8 applied where Amazon's data actually lives:
     * the Confirmation group is per rate, so OnTrac Ground is excluded from a
     * signature-required shipment while the UPS offer beside it is not. Doing
     * it at `offerCapability()` would have excluded Amazon entirely, and doing
     * it at purchase would have excluded it after the money.
     *
     * `$specialServiceCodes` here is already the hard-required set plus the
     * defaults `ShippingRateService` decided this source could be asked for; a
     * default nobody can honour is dropped from the purchase instead, which is
     * what {@see AmazonBuyShippingService::confirmationPreferences()} does.
     */
    private function honoursRequiredServices(array $rate, RateRequest $request): bool
    {
        $offered = collect($rate['availableValueAddedServiceGroups'] ?? [])
            ->flatMap(fn (array $group): array => collect($group['valueAddedServices'] ?? [])->pluck('id')->all());

        foreach ($request->specialServiceCodes as $code) {
            $vas = self::SUPPORTED_SERVICES[$code] ?? null;

            // Declared value rides on the package rather than on a
            // value-added service, so no group has to offer it.
            if ($vas === null || $code === 'declared_value') {
                continue;
            }

            if (! $offered->contains($vas)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drop an offer whose required value-added service group can only be
     * answered with a surcharge nobody asked for.
     *
     * A required group must be answered with an option it offers (`10`), and
     * {@see AmazonBuyShippingService::valueAddedServicesFor()} answers with the
     * cheapest one when nothing was requested. Every group seen so far has a
     * free option — UPS's `NO_CONFIRMATION`, USPS's `DELIVERY_CONFIRMATION` —
     * but a group that had none would make that answer a paid signature the
     * quoted price did not include. That rate is dropped here, unless the
     * shipment asked for one of the paid options, in which case it is paying
     * for it knowingly.
     */
    private function answersRequiredGroupsForFree(array $rate, RateRequest $request): bool
    {
        $wanted = collect($request->specialServiceCodes)
            ->map(fn (string $code): ?string => self::SUPPORTED_SERVICES[$code] ?? null)
            ->filter();

        foreach ($rate['availableValueAddedServiceGroups'] ?? [] as $group) {
            if (! ($group['isRequired'] ?? false)) {
                continue;
            }

            $offered = collect($group['valueAddedServices'] ?? [])->pluck('id');

            if ($wanted->intersect($offered)->isNotEmpty()) {
                continue;
            }

            $cheapest = AmazonBuyShippingService::cheapestOption($group);

            if ($cheapest === null || (float) ($cheapest['cost']['value'] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drop an offer whose packaging requirement the Package does not meet.
     *
     * The same `accepts()` the shared filter runs, on the same requirement
     * {@see classifyPackaging()} stamps on the rate — so a flat-rate envelope
     * offer is dropped for a parcel in a box and kept for one in that
     * envelope, and a One Rate offer is dropped for the packer's own box and
     * kept for a Package in any FedEx packaging. Why it runs here at all is on
     * {@see isBuyable()}.
     */
    private function fitsThePackaging(array $rate, RateRequest $request): bool
    {
        $package = $request->packages[0] ?? null;

        return $this->classifyPackaging((string) ($rate['serviceId'] ?? ''))
            ->accepts($package?->carrierPackaging);
    }

    /**
     * Drop an offer for content the Package cannot vouch for — Media Mail and
     * Bound Printed Matter, always, until the app can say a Package's contents
     * qualify. Content classes are deferred past ADR-0005 to a later ADR; this
     * is `amazon-buy-shipping/12`'s rule 3, which outlives its rules 1 and 2.
     */
    private function carriesPermittedContent(array $rate): bool
    {
        return ! in_array((string) ($rate['serviceId'] ?? ''), self::CONTENT_RESTRICTED_SERVICES, true);
    }

    /**
     * What the purchase will need from the quote, and what a person reading the
     * offer later will want.
     *
     * `supportedDocumentSpecifications` is the load-bearing entry: the document
     * format has to be validated against the *chosen rate*, and re-quoting to
     * find out which sizes it offered would invalidate the token being spent.
     * The packaging requirement rides along for the same reason: the purchase
     * re-checks it against the Package, and must read the one the quote stated.
     *
     * @return array<string, mixed>
     */
    private function rateMetadata(array $rate, PackagingRequirement $packagingRequirement): array
    {
        return array_filter([
            'amazonCarrierId' => $rate['carrierId'] ?? null,
            'amazonServiceId' => $rate['serviceId'] ?? null,
            PackagingRequirement::RATE_METADATA_KEY => $packagingRequirement->toArray(),
            'supportedDocumentSpecifications' => $rate['supportedDocumentSpecifications'] ?? [],
            // Whether a second document comes back, for the report-printer
            // gate — derived from the offering, and re-derived at purchase
            // from the specifications stored beside it.
            self::CUSTOMS_DOCUMENT_METADATA_KEY => AmazonBuyShippingService::declaresCustomsForm(
                $rate['supportedDocumentSpecifications'] ?? []
            ),
            'availableValueAddedServiceGroups' => $rate['availableValueAddedServiceGroups'] ?? [],
            // Buy Shipping protection: a reason to prefer this offer that has
            // nothing to do with price, and worth keeping on the package.
            'benefits' => $rate['benefits'] ?? null,
            'promise' => $rate['promise'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * The carrier-agnostic codes actually bought, for the package's record.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<int, string>
     */
    private function appliedServices(array $metadata, ShipRequest $request): array
    {
        $offered = collect($metadata['availableValueAddedServiceGroups'] ?? [])
            ->flatMap(fn (array $group): array => collect($group['valueAddedServices'] ?? [])->pluck('id')->all());

        return collect(self::SUPPORTED_SERVICES)
            ->filter(fn (string $vas, string $code): bool => $code !== 'declared_value'
                && $request->hasSpecialService($code)
                && $offered->contains($vas))
            ->keys()
            ->all();
    }

    private function packageFor(RateRequest $request): ?Package
    {
        return $request->packageId
            ? Package::with(['shipment.dataSource', 'packageItems.shipmentItem', 'packageItems.product', 'location'])
                ->find($request->packageId)
            : null;
    }
}
