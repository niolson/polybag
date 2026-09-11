<?php

namespace App\Services\Carriers;

use App\Contracts\AsyncRateQuoting;
use App\Contracts\RecoversUnresolvedPurchase;
use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\PostageSources\OfferDraft;
use App\DataTransferObjects\PostageSources\ServiceObservation;
use App\DataTransferObjects\Shipping\AmazonPurchasedLabel;
use App\DataTransferObjects\Shipping\AmazonShippingQuote;
use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PostageSource;
use App\Enums\ServiceCapability;
use App\Enums\ServiceEvidence;
use App\Enums\SourceEnvironment;
use App\Exceptions\Carriers\AmazonLabelPurchaseException;
use App\Exceptions\MissingAmazonOrderItemsException;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Models\DataSource;
use App\Models\ObservedService;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Services\AmazonBuyShippingService;
use App\Services\PostageSources\ObservedServiceRecorder;
use App\Services\PostageSources\OfferStore;
use App\Services\RateSelector;
use App\Services\ShipmentImport\AmazonOrderItems;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Saloon\Http\Response;

/**
 * Buys postage through Amazon Buy Shipping rather than through a carrier
 * account of our own.
 *
 * Amazon is not a carrier, and unlike Shopify it does not pretend to be one
 * either: a single `getRates` for one parcel came back with OnTrac, UPS and
 * USPS priced independently, against a catalog of 102 more it declined
 * (`amazon-buy-shipping/01`). So each rate keeps the carrier Amazon named for
 * *that offer* — which is the whole reason quoting and purchasing had to be
 * split off carrier-name dispatch first (`postage-source-split/08`). A rate
 * carried by OnTrac says OnTrac; buying it still calls Amazon.
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
 *   Filtering after is what `amazon-buy-shipping/08` is still deciding about,
 *   and post-quote is the stopgap it names.
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

    public function getCarrierName(): string
    {
        return self::SOURCE_NAME;
    }

    /**
     * Configured when any active Amazon data source exists — that source
     * carries the credentials *and* the orders labels are bought against.
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
        $package = $this->packageFor($request);
        $service = app(AmazonBuyShippingService::class);

        if (! $package || ! $service->canQuoteFor($package) || $request->packages === []) {
            return null;
        }

        $source = $service->dataSourceFor($package);
        $orderId = app(AmazonOrderItems::class)->orderIdFor($package);

        if (! $source || ! $orderId) {
            return null;
        }

        try {
            $payload = $service->buildRatePayload($package, $request, $orderId);
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

        return new PreparedRateRequest(
            pendingRequest: $service->connectorFor($source)->createPendingRequest(
                new GetShippingRates($payload, AmazonBuyShippingService::BUSINESS_ID)
            ),
            carrierName: self::SOURCE_NAME,
        );
    }

    /**
     * @param  array<string>  $serviceCodes
     * @return Collection<int, RateResponse>
     */
    public function parseRateResponse(Response $response, RateRequest $request, array $serviceCodes): Collection
    {
        $package = $this->packageFor($request);

        if (! $package) {
            return collect();
        }

        if (! $response->successful()) {
            logger()->warning('Amazon getRates failed', [
                'package_id' => $package->id,
                'status' => $response->status(),
            ]);

            return collect();
        }

        return $this->ratesFrom(
            AmazonShippingQuote::fromPayload($response->json('payload', [])),
            $package,
            $request,
        );
    }

    /**
     * @param  array<string>  $serviceCodes
     * @return Collection<int, RateResponse>
     */
    public function getRates(RateRequest $request, array $serviceCodes): Collection
    {
        $package = $this->packageFor($request);

        if (! $package) {
            return collect();
        }

        try {
            $quote = app(AmazonBuyShippingService::class)->quote($package, $request);
        } catch (MissingAmazonOrderItemsException $e) {
            logger()->info('Skipped an Amazon quote for a package Amazon cannot identify', [
                'package_id' => $package->id,
                'reason' => $e->getMessage(),
            ]);

            return collect();
        }

        return $quote === null ? collect() : $this->ratesFrom($quote, $package, $request);
    }

    /**
     * Nothing to resolve: an Amazon rate already *is* a specific offer, priced
     * and tokenized. The variant-picking `UspsAdapter` does here has no analogue
     * — there is no second call that would narrow one Amazon `rateId` into
     * another.
     */
    public function resolvePreSelectedRate(RateResponse $rate, Package $package): RateResponse
    {
        return $rate;
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
            shipDate: $request->shipDate,
            appliedServices: $this->appliedServices($metadata, $request),
            postageSource: PostageSource::PostageDataSource,
            postageDataSourceId: $offer->postage_data_source_id
                ?? app(AmazonBuyShippingService::class)->postageSourceFor($package)?->id,
            metadata: array_filter([
                self::SHIPMENT_ID_KEY => $label->shipmentId,
                // Amazon's own carrier identifier, not our `Carrier` row's name.
                // `getTracking` takes it, and a courier we hold no row for has
                // no other handle.
                self::CARRIER_ID_KEY => $metadata['amazonCarrierId'] ?? null,
                'amazon_service_id' => $metadata['amazonServiceId'] ?? null,
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
     * @return Collection<int, RateResponse>
     */
    private function ratesFrom(AmazonShippingQuote $quote, Package $package, RateRequest $request): Collection
    {
        $source = app(AmazonBuyShippingService::class)->dataSourceFor($package);
        $marketplace = $source ? app(AmazonBuyShippingService::class)->marketplaceIdFor($source) : null;

        $observations = $this->record($quote, $marketplace);
        $environment = SourceEnvironment::current();
        $expiresAt = now()->addSeconds(AmazonBuyShippingService::OFFER_WINDOW_SECONDS);
        $offerStore = app(OfferStore::class);

        return collect($quote->rates)
            ->filter(fn (array $rate): bool => $this->isBuyable($rate, $request))
            ->map(function (array $rate) use (
                $package, $observations, $environment, $expiresAt, $offerStore, $quote, $source, $marketplace
            ): RateResponse {
                $carrierId = (string) $rate['carrierId'];
                $serviceId = (string) $rate['serviceId'];
                $mapped = $observations->get(ObservedService::serviceKey(self::OBSERVATION_SOURCE, $carrierId, $serviceId))
                    ?->carrierService;

                $carrier = $mapped?->carrier->name ?? (string) ($rate['carrierName'] ?? $carrierId);
                $serviceName = $mapped->name ?? (string) ($rate['serviceName'] ?? $serviceId);
                $price = round((float) ($rate['totalCharge']['value'] ?? 0), 2);
                $currency = (string) ($rate['totalCharge']['unit'] ?? 'USD');

                $offer = $offerStore->issue($package, new OfferDraft(
                    carrier: $carrier,
                    postageSource: PostageSource::PostageDataSource,
                    postageDataSourceId: $source?->id,
                    // The service code an authored mapping gives it, so a
                    // shipping rule written against Ground Advantage matches
                    // whichever source quoted it. Unmapped, Amazon's own
                    // identifier stands in — it is what the service *is*.
                    serviceCode: $mapped->service_code ?? $serviceId,
                    serviceName: $serviceName,
                    price: $price,
                    currency: $currency,
                    rateMetadata: $this->rateMetadata($rate),
                    purchaseContext: [
                        'requestToken' => $quote->requestToken,
                        'rateId' => (string) $rate['rateId'],
                    ],
                    expiresAt: $expiresAt,
                    marketplace: $marketplace,
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
                        externalCarrierId: $carrierId,
                        externalServiceId: $serviceId,
                    ),
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
     * Whether this offer could actually be bought and printed.
     *
     * Two filters, both of which would otherwise fail the *purchase* rather
     * than the quote — after the packer has committed and, for the second one,
     * after the offer has been spent.
     */
    private function isBuyable(array $rate, RateRequest $request): bool
    {
        return $this->hasPrintableDocument($rate)
            && $this->honoursRequiredServices($rate, $request)
            && $this->answersRequiredGroupsForFree($rate, $request);
    }

    /**
     * PNG is offered by every rate observed so far and is not a format our
     * printing path can send to QZ Tray. A rate offering nothing else is
     * unbuyable and is dropped here rather than at purchase time.
     */
    private function hasPrintableDocument(array $rate): bool
    {
        return collect($rate['supportedDocumentSpecifications'] ?? [])
            ->contains(fn (array $spec): bool => in_array($spec['format'] ?? null, ['PDF', 'ZPL'], true));
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
     * What the purchase will need from the quote, and what a person reading the
     * offer later will want.
     *
     * `supportedDocumentSpecifications` is the load-bearing entry: the document
     * format has to be validated against the *chosen rate*, and re-quoting to
     * find out which sizes it offered would invalidate the token being spent.
     *
     * @return array<string, mixed>
     */
    private function rateMetadata(array $rate): array
    {
        return array_filter([
            'amazonCarrierId' => $rate['carrierId'] ?? null,
            'amazonServiceId' => $rate['serviceId'] ?? null,
            'supportedDocumentSpecifications' => $rate['supportedDocumentSpecifications'] ?? [],
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
