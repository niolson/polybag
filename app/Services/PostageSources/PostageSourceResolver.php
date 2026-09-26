<?php

namespace App\Services\PostageSources;

use App\DataTransferObjects\PostageSources\PostageSourceCandidate;
use App\DataTransferObjects\PostageSources\PostageSourceResolution;
use App\Enums\PostageSourceKind;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\ShippingMethod;
use App\Services\Carriers\CarrierRegistry;
use App\Services\ShipmentImport\AmazonOrderItems;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Support\Collection;

/**
 * Decides which postage source is asked for a package, before anything is asked.
 *
 * ADR-0002 decision 9. Once more than one source can supply a shipment, "follow
 * the `CarrierAccount` pattern" stops being enough: that pattern binds an offer
 * to a *carrier*, and what a purchase needs is the specific source *instance*
 * that will sell it.
 *
 * The two arms answer genuinely different questions, which is why they do not
 * share a mechanism:
 *
 * - **Channel postage binds to the shipment's originating data source and
 *   nothing else.** A Shopify or Amazon Buy Shipping purchase is keyed to a
 *   fulfillment order that exists in exactly one account, so scoping does not
 *   enter into it: either the shipment came from that account or the source is
 *   not a candidate at all. There is no precedence chain here to get wrong and
 *   no fallback — a shipment that came from nowhere resolves to nothing.
 * - **Carrier accounts resolve by scope**, on the existing `(location, client)`
 *   precedence. This arm covers direct USPS, UPS and FedEx accounts.
 * - **Off-Amazon Amazon Shipping resolves by scope too** — Shipping v2 calls it
 *   an `EXTERNAL` channel. ADR-0002's 2026-09-22 amendment selects it with a
 *   `carrier_account_scopes` row that targets an Amazon `DataSource`, on the
 *   same precedence, independently of the Shipment's import source, and never
 *   for an Amazon-originating Shipment.
 *
 * This resolves *who may sell*, at quote time. What a shipped package's postage
 * actually was is recorded provenance, read by `PostageSourceDispatcher` from
 * the `postage_source` discriminator — never re-derived from here, since a
 * shipment can be re-pointed at another source after its label was bought.
 */
class PostageSourceResolver
{
    /**
     * Import drivers whose account can also sell postage.
     *
     * A driver's presence here says the *kind* of account can sell postage, not
     * that this one is entitled to. Amazon's entitlement is settled per order
     * by `getRates` itself: a seller who cannot buy a service is told so in
     * `ineligibleRates`, and one who cannot buy any gets an empty rate list —
     * which is an absence of offers, not an error.
     *
     * @var array<int, class-string>
     */
    private const CHANNEL_POSTAGE_DRIVERS = [ShopifySource::class, AmazonSource::class];

    public function __construct(
        private readonly CarrierRegistry $carrierRegistry,
        private readonly AmazonOrderItems $amazonOrderItems,
    ) {}

    /**
     * The channel postage source bound to this package, or null.
     *
     * Null covers every way a package can fail to have one, deliberately
     * without distinguishing them: no shipment, an import that came from a
     * database query, a source deactivated since, a driver that sells no
     * postage. None of them is a reason to look at a second account — reading
     * one shop's fulfillment orders with another shop's credentials is the
     * failure this rule exists to make impossible.
     */
    public function channelSourceFor(Package $package): ?DataSource
    {
        $package->loadMissing('shipment.dataSource');

        $source = $package->shipment?->dataSource;

        if (! $source || ! $source->active) {
            return null;
        }

        return in_array($source->source_type, self::CHANNEL_POSTAGE_DRIVERS, true)
            ? $source
            : null;
    }

    /**
     * The Amazon connection scoped to sell Amazon Shipping for this package's
     * order from another channel, or null.
     *
     * An Amazon order never gets one, even when the connection it came from is
     * inactive: sold as `EXTERNAL`, its label would lose its link to the Amazon
     * order. It is recognized by the connection it was imported from or by the
     * Amazon order ID the import recorded, so an order whose connection has
     * since been deleted is still an Amazon order.
     */
    public function offAmazonShippingSourceFor(Package $package): ?DataSource
    {
        $package->loadMissing('shipment.dataSource');

        if ($this->isAmazonOrder($package)) {
            return null;
        }

        return DataSource::resolveOffAmazonShipping($package->location_id, $package->shipment?->client_id);
    }

    /**
     * Whether this package's order came from Amazon, recognized by the
     * connection it was imported from or by the Amazon order ID the import
     * recorded, so an order whose connection has since been deleted still is.
     */
    public function isAmazonOrder(Package $package): bool
    {
        return $package->shipment?->dataSource?->isAmazon()
            || $this->amazonOrderItems->orderIdFor($package) !== null;
    }

    /**
     * Every source that could sell this package a label: the bound channel
     * source when its postage setting sells, the connection scoped to sell
     * Amazon Shipping directly, then one per direct carrier with a
     * `CarrierAccount` (ADR-0006 decision 4).
     *
     * Amazon Shipping sold to an order from another channel is a direct sale
     * on a connection's account (`carrier-catalog-reset/15`). Its candidate is
     * the connection, because that is what an Offer and a Label record as the
     * postage source, but it is asked only where a direct carrier would be:
     * when the method has its `direct` row and lists an Amazon Shipping
     * service, or when there is no method.
     *
     * The direct carriers are those the shipping method's active services
     * name, or every carrier with a direct integration when there is no
     * method. A method without its `direct` policy row gets none: direct is on
     * by default, and deleting the row turns it off
     * (`carrier-catalog-reset/09`). Which of a method's services each source
     * then sells is rating's question, not this one's.
     *
     * Each direct carrier gets the first account its scopes resolve, and null
     * when none does. A `rate_shop` scope still yields one account: the
     * purchase path checks an offer against the first account only, so an
     * offer from a second would be refused as "Carrier Account Changed"
     * (ADR-0006, *Foreseen, not decided*). One account per band is not
     * enforced here either, because it cannot be violated:
     * `carrier_account_scopes` is unique on `(carrier_id, location_key,
     * client_key)`, so each precedence band holds at most one scope and the
     * walk has nothing to arbitrate.
     *
     * Only carriers with a direct integration are walked, so an account left
     * on a resale channel's or a policy-only carrier's row, from before
     * `CarrierAccount` refused them, is never read.
     */
    public function resolve(Package $package, ?ShippingMethod $shippingMethod = null): PostageSourceResolution
    {
        /** @var Collection<int, PostageSourceCandidate> $candidates */
        $candidates = new Collection;

        // A connection that does not sell postage is not asked (ADR-0006
        // decision 6). It stays the channel source for everything else.
        $channel = $this->channelSourceFor($package);

        if ($channel && $channel->postageSetting()->sells()) {
            $candidates->push(PostageSourceCandidate::fromDataSource($channel));
        }

        if ($this->sellsAmazonShippingDirectly($shippingMethod)
            && $offAmazon = $this->offAmazonShippingSourceFor($package)) {
            $candidates->push(PostageSourceCandidate::forOffAmazonShipping($offAmazon));
        }

        $package->loadMissing('shipment');
        $locationId = $package->location_id;
        $clientId = $package->shipment?->client_id;

        foreach ($this->directCarriers($shippingMethod) as $carrierName => $carrierId) {
            $account = $carrierId === null
                ? null
                : CarrierAccount::resolveForShipment($carrierId, $locationId, $clientId)->first();

            $candidates->push(PostageSourceCandidate::forDirectCarrier($carrierName, $account));
        }

        return new PostageSourceResolution($candidates);
    }

    /**
     * Whether this method would buy Amazon Shipping directly: with no method,
     * which allows every direct service, or with its `direct` row and an
     * active Amazon Shipping service listed.
     */
    private function sellsAmazonShippingDirectly(?ShippingMethod $shippingMethod): bool
    {
        if ($shippingMethod === null) {
            return true;
        }

        return $shippingMethod->allowsSource(PostageSourceKind::Direct)
            && $shippingMethod->carrierServices()
                ->active()
                ->withActiveCarrier()
                ->whereHas('carrier', fn ($query) => $query->where('name', Carrier::AMAZON_SHIPPING))
                ->exists();
    }

    /**
     * The carriers sold directly that this method needs, by their fixed name,
     * with each one's carrier row id, or null for a registered integration
     * with no row.
     *
     * Asked as `directAdapterFor()` and not `policyFor()`, because a candidate
     * is a claim that we can *buy* here, and the pairing that has to hold is
     * with `CarrierAccountPostageSource`, which voids and tracks through
     * `directAdapterOrFail()`. Amazon Shipping is not one: its adapter takes a
     * connection, not a `CarrierAccount`, and is asked through the connection
     * candidate above.
     *
     * @return array<string, int|null>
     */
    private function directCarriers(?ShippingMethod $shippingMethod): array
    {
        if ($shippingMethod !== null && ! $shippingMethod->allowsSource(PostageSourceKind::Direct)) {
            return [];
        }

        if ($shippingMethod !== null) {
            return $shippingMethod->carrierServices()
                ->active()
                ->withActiveCarrier()
                ->with('carrier')
                ->get()
                ->pluck('carrier')
                ->unique('id')
                ->filter(fn (Carrier $carrier): bool => $this->carrierRegistry->directAdapterFor($carrier->name) !== null)
                ->mapWithKeys(fn (Carrier $carrier): array => [$carrier->name => $carrier->id])
                ->all();
        }

        $names = array_values(array_filter(
            $this->carrierRegistry->getCarrierNames(),
            fn (string $name): bool => $this->carrierRegistry->directAdapterFor($name) !== null,
        ));
        $ids = Carrier::whereIn('name', $names)->pluck('id', 'name');

        return collect($names)
            ->mapWithKeys(fn (string $name): array => [$name => $ids->get($name)])
            ->all();
    }
}
