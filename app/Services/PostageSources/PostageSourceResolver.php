<?php

namespace App\Services\PostageSources;

use App\DataTransferObjects\PostageSources\PostageSourceCandidate;
use App\DataTransferObjects\PostageSources\PostageSourceResolution;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\Package;
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

    private function isAmazonOrder(Package $package): bool
    {
        return $package->shipment?->dataSource?->isAmazon()
            || $this->amazonOrderItems->orderIdFor($package) !== null;
    }

    /**
     * Every source eligible to sell this package a label: the bound channel
     * source, the connection scoped to sell off-Amazon Amazon Shipping, then
     * one carrier account per named carrier.
     *
     * Carriers are named by the caller because it already knows which ones it
     * is about to quote — the shipping method's services, or every configured
     * adapter when there is no method. Resolving is not free (a query and a
     * precedence walk per carrier), and resolving carriers nobody asked about
     * would spend it for nothing.
     *
     * One source per carrier comes out of this, unless the winning scope opted
     * into rate shopping. It is not enforced here on top of the scope walk,
     * because it cannot be violated there: `carrier_account_scopes` is unique
     * on `(carrier_id, location_key, client_key)`, so each of the four
     * precedence bands holds at most one scope and the walk has nothing to
     * arbitrate. That constraint, not a check in this class, is what makes
     * "never an arbitrary pick" true for direct carriers.
     *
     * @param  array<int, string>  $carrierNames
     */
    public function resolve(Package $package, array $carrierNames = []): PostageSourceResolution
    {
        /** @var Collection<int, PostageSourceCandidate> $candidates */
        $candidates = new Collection;
        $conflicts = [];

        if ($channel = $this->channelSourceFor($package)) {
            $candidates->push(PostageSourceCandidate::fromDataSource($channel));
        }

        // Always asked, like the channel arm, because no carrier name selects
        // it: the offer's carrier is whatever Amazon Shipping quotes.
        if ($offAmazon = $this->offAmazonShippingSourceFor($package)) {
            $candidates->push(PostageSourceCandidate::forOffAmazonShipping($offAmazon));
        }

        $package->loadMissing('shipment');
        $locationId = $package->location_id;
        $clientId = $package->shipment?->client_id;

        $names = array_values(array_unique($carrierNames));
        $carrierIds = Carrier::whereIn('name', $names)->pluck('id', 'name');

        foreach ($names as $carrierName) {
            $carrierId = $carrierIds->get($carrierName);

            if ($carrierId === null) {
                continue;
            }

            $accounts = CarrierAccount::resolveForShipment($carrierId, $locationId, $clientId);

            if ($accounts->isEmpty()) {
                continue;
            }

            // Asked as `directAdapterFor()` and not `policyFor()`, because the
            // candidate about to be emitted is a claim that we can *buy* here,
            // and holding a carrier's policy is not holding an account with it.
            // The two coincide for every adapter registered today, so this is
            // the weaker predicate only by luck; the pairing that has to hold is
            // with `CarrierAccountPostageSource`, which resolves the same
            // package through `directAdapterOrFail()` when the time comes to
            // void or track it. Resolving on the looser question would promise a
            // packer an offer that throws on the way back out.
            //
            // Two kinds of carrier row fail it. A resale channel holds one so
            // its offers have services to hang off — an account scoped there
            // claims we buy postage from a storefront the way we buy it from
            // USPS, when its postage comes from the data source above, on the
            // merchant's own account. A policy-only carrier holds one so the
            // cutoffs and manifest behavior of a courier Shopify picked come out
            // right (ADR-0002 option D), and we buy nothing from it at all.
            // Either way two sources would claim one carrier, and neither may be
            // picked over the other.
            if (! $this->carrierRegistry->directAdapterFor($carrierName)) {
                $conflicts[] = [
                    'carrier' => $carrierName,
                    'reason' => "No account of ours buys postage from {$carrierName}, so the carrier account scoped to it cannot sell this label. Remove it in Carrier Accounts — a carrier row also exists for carriers we only hold policy for, and for resale channels like Shopify, whose postage resolves to the data source the shipment came from instead.",
                ];

                continue;
            }

            foreach ($accounts as $account) {
                $candidates->push(PostageSourceCandidate::fromCarrierAccount($account, $carrierName));
            }
        }

        return new PostageSourceResolution($candidates, $conflicts);
    }
}
