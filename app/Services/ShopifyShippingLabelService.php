<?php

namespace App\Services;

use App\DataTransferObjects\ShipmentImport\ShopifyFulfillmentOrderIdentity;
use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\DataTransferObjects\Shipping\ShopifyPurchasedLabel;
use App\Enums\PostageSource;
use App\Exceptions\Carriers\ShopifyLabelPurchaseException;
use App\Exceptions\ShopifyDeclaredWeightException;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Http\Integrations\Shopify\ShopifyConnector;
use App\Models\DataSource;
use App\Models\Package;
use App\Services\PostageSources\PostageSourceResolver;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Buys shipping labels through Shopify Shipping.
 *
 * Shopify's 2026-07 Admin API exposes exactly one write operation for this —
 * `shippingLabelPurchase` — and no way to quote a rate first, read what a label
 * cost, or void one afterwards. The purchase is asynchronous: the mutation
 * enqueues a job and returns a result node that has to be polled until it
 * reaches PURCHASED or PURCHASE_FAILED.
 *
 * Credentials come from the Shopify `DataSource` the shipment was imported
 * from, which is also the only place a fulfillment order ID exists — labels
 * can't be bought for a shipment that didn't come from Shopify.
 */
class ShopifyShippingLabelService
{
    /**
     * Shopify's carrier codes for `preferredRateSelection`, and the carrier each
     * one means. FedEx is absent because Shopify refuses FedEx purchases through
     * this API.
     *
     * The names matter as much as the codes: `trackingInfo.company` on a
     * `ShippingLabel` returns the *code*, not a carrier name, so the carrier of
     * record has to be translated before anything tries to resolve it. USPS is
     * the trap — its code and its name are the same string, so a USPS label
     * resolves by coincidence and hides that the others do not.
     */
    public const CARRIER_NAMES = [
        'usps' => 'USPS',
        'ups_shipping' => 'UPS',
        'dhl_express' => 'DHL Express',
        'canada_post' => 'Canada Post',
    ];

    /**
     * Scopes `shippingLabelPurchase` needs, on top of the import scopes in
     * ShopifyFulfillmentOrderActivationService. Deliberately kept separate:
     * a data source that only imports orders must not be blocked from
     * activating or syncing locations because it cannot buy postage.
     *
     * The staff user also needs the `buy_shipping_labels` permission, which is
     * not an OAuth scope and cannot be checked through the API.
     *
     * `read_products` is here for {@see DECLARED_ITEM_WEIGHT_QUERY}, which
     * traverses `lineItem.variant` to read what Shopify will declare in customs.
     * It is deliberately *not* added to
     * `ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES`: that list is
     * enforced at activation and on every location sync, and promoting a
     * label-purchase dependency into it would refuse location syncs to a source
     * that only imports orders and never buys postage — the separation this
     * constant exists for. Nothing enforces this list as a gate; it reaches the
     * connect-time scope parameter, and the purchase path degrades rather than
     * fails when the scope is absent.
     */
    public const REQUIRED_SCOPES = ['write_orders', 'write_merchant_managed_fulfillment_orders', 'read_products'];

    public function __construct(
        private readonly PostageSourceResolver $postageSourceResolver,
        private readonly ShopifyGoodsFingerprint $goods,
    ) {}

    /**
     * Re-reads a label already bought, so a purchase that succeeded at Shopify
     * but failed on our side can be recovered instead of bought twice.
     */
    private const SHIPPING_LABEL_QUERY = <<<'GRAPHQL'
        query ShopifyShippingLabel($id: ID!) {
          shippingLabel(id: $id) {
            id
            trackingInfo { company number url }
            shippingDocuments { documentType format url }
          }
        }
        GRAPHQL;

    private const PURCHASE_MUTATION = <<<'GRAPHQL'
        mutation PurchaseShippingLabel($input: ShippingLabelPurchaseInput!) {
          shippingLabelPurchase(shippingLabelPurchase: $input) {
            shippingLabelPurchaseResult {
              id status done
              errors { code message }
            }
            userErrors { field code message }
          }
        }
        GRAPHQL;

    private const PURCHASE_STATUS_QUERY = <<<'GRAPHQL'
        query ShippingLabelPurchaseStatus($id: ID!) {
          node(id: $id) {
            ... on ShippingLabelPurchaseResult {
              id status done
              errors { code message }
              shippingLabels {
                id
                trackingInfo { company number url }
                shippingDocuments { documentType format url }
              }
            }
          }
        }
        GRAPHQL;

    /**
     * Everything Shopify will tell us about a label after it was bought.
     *
     * Two questions, one request. Shopify has no "was this label voided" query,
     * so the void answer comes from the fulfillment the purchase created:
     * voiding a label in the admin moves it to a LABEL_VOIDED display status,
     * and cancelling the fulfillment outright sets its status to CANCELLED. The
     * same `displayStatus` carries the delivery lifecycle once the parcel moves,
     * which is the only tracking we are entitled to for postage bought on
     * Shopify's account (ADR-0002).
     *
     * `events` is asked for on the chance Shopify populates it. The documented
     * way events are created is `fulfillmentEventCreate`, called by apps and
     * fulfillment services, so it may always come back empty here — costing one
     * field on a request already being made. It is not worth a second query and
     * its absence is not an error.
     */
    private const FULFILLMENT_STATE_QUERY = <<<'GRAPHQL'
        query ShopifyFulfillmentState($id: ID!) {
          fulfillmentOrder(id: $id) {
            id
            status
            fulfillments(first: 20) {
              nodes {
                id
                status
                displayStatus
                inTransitAt
                deliveredAt
                estimatedDeliveryAt
                trackingInfo { number company url }
                events(first: 50) {
                  nodes {
                    id
                    status
                    happenedAt
                    message
                    city
                    province
                    zip
                    country
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

    /**
     * The order's fulfillment orders, for finding the one that replaced a
     * fulfillment order a void closed.
     *
     * `supportedActions` rather than status alone: `CREATE_FULFILLMENT` is the
     * honest test of whether a label can still be bought against a fulfillment
     * order, and it is what separates the replacement from the husk the void
     * left behind. `includeClosed: false` drops most of those anyway, but a
     * fulfillment order can be open and still unfulfillable — on hold, or
     * assigned to a third-party fulfillment service.
     *
     * The line items come too, because fulfillable and at the right location is
     * not enough to make one a replacement: an order split at a single location
     * has siblings that pass both tests and are somebody else's goods.
     *
     * Page sizes match the import's proven query shape rather than the maximum,
     * and **both** connections report whether they were truncated. Neither is
     * paginated: an order carrying more than twenty fulfillment orders, or one
     * of them more than forty line items, is far outside anything a void has to
     * be resolved against, and a partial page cannot be told apart from a
     * complete one by looking at it. `hasNextPage` is what stops it being read
     * as complete — a truncated page is no answer rather than a wrong one, and
     * the import, which does paginate, resolves it on the next run.
     */
    private const ORDER_FULFILLMENT_ORDERS_QUERY = <<<'GRAPHQL'
        query ShopifyOrderFulfillmentOrders($id: ID!) {
          order(id: $id) {
            id
            fulfillmentOrders(first: 20, includeClosed: false) {
              pageInfo { hasNextPage }
              nodes {
                id
                status
                supportedActions { action }
                assignedLocation { location { id } }
                lineItems(first: 40) {
                  pageInfo { hasNextPage }
                  nodes {
                    sku remainingQuantity requiresShipping
                    variant { id }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

    /**
     * The weight Shopify will put on the customs form, item by item — asked for
     * twice, because the better answer is the one that can be refused.
     *
     * `inventoryItem.measurement.weight` is the live catalogue value and the
     * one preferred. `FulfillmentOrderLineItem.weight`, which the import
     * already reads, is a snapshot taken when the order was placed. The two
     * normally agree, and the difference is the whole point: the declaration is
     * built at purchase time from the catalogue, so a merchant who corrects a
     * product weight in response to a refusal has to be able to retry
     * successfully, and the snapshot alone would keep refusing.
     *
     * **The live value carries a scope the snapshot does not.** Reaching it
     * traverses `lineItem.variant`, which Shopify gates behind `read_products`
     * — see {@see REQUIRED_SCOPES}. That scope is not in
     * `ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES`, so a store
     * activated before this shipped may not have granted it, and asking for it
     * alone would turn a missing scope into a failed purchase on every
     * international label. Both fields ride on one request precisely so the
     * snapshot survives a denied traversal: the check degrades to order-time
     * weights instead of disappearing, and {@see declaredItemWeight()} treats
     * the errors as advisory rather than fatal.
     *
     * Forty line items, matching the import's proven page size, and
     * `hasNextPage` is reported so a truncated page can be treated as no answer
     * rather than as a smaller sum — under-counting here would let through
     * exactly the purchase this exists to withhold.
     */
    private const DECLARED_ITEM_WEIGHT_QUERY = <<<'GRAPHQL'
        query ShopifyDeclaredItemWeight($id: ID!) {
          fulfillmentOrder(id: $id) {
            id
            lineItems(first: 40) {
              pageInfo { hasNextPage }
              nodes {
                remainingQuantity
                weight { value unit }
                lineItem {
                  variant {
                    inventoryItem {
                      measurement { weight { value unit } }
                    }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

    /**
     * How far below Shopify's declared weight a scale reading may fall before
     * it stops being measurement and starts being a data defect, in pounds.
     *
     * Physically a packed box outweighs its contents, so any shortfall at all
     * is somebody being imprecise. Within 0.1 lb — 1.6 oz — that somebody is
     * the scale: the reading is rounded to two decimals, scales resolve to
     * tenths of an ounce at best and twentieths of a pound at worst, and the
     * carrier rounds up to the next ounce regardless, so declaring the sum
     * instead of the reading costs nothing and says nothing meaningfully
     * untrue. A wider gap than that is not the scale being imprecise, it is the
     * catalogue describing goods that are not in the box, and no amount of
     * arithmetic here makes that true.
     */
    public const DECLARED_WEIGHT_TOLERANCE = 0.1;

    /** Fulfillment states that mean the label PolyBag holds is no longer live. */
    private const VOIDED_STATES = ['LABEL_VOIDED', 'CANCELED', 'CANCELLED'];

    /**
     * Buy a label for the package's Shopify fulfillment order.
     *
     * @param  string|null  $carrierCode  Shopify carrier code, or null to let Shopify choose the rate
     * @param  string|null  $serviceCode  Carrier-defined service code, required when $carrierCode is given
     *
     * @throws ShopifyLabelPurchaseException
     * @throws ShopifyDeclaredWeightException
     */
    public function purchase(
        Package $package,
        ShipRequest $request,
        ?string $carrierCode = null,
        ?string $serviceCode = null,
    ): ShopifyPurchasedLabel {
        $dataSource = $this->dataSourceFor($package);

        if (! $dataSource) {
            throw new ShopifyLabelPurchaseException(
                'This shipment did not come from an active Shopify data source, so no Shopify Shipping label can be bought for it.'
            );
        }

        $fulfillmentOrderId = $this->fulfillmentOrderId($package);

        if (! $fulfillmentOrderId) {
            throw new ShopifyLabelPurchaseException(
                'This shipment has no Shopify fulfillment order ID, so no Shopify Shipping label can be bought for it.'
            );
        }

        $connector = ShopifyConnector::fromSettings(
            array_merge($dataSource->settings ?? [], $dataSource->secret_settings ?? [])
        );

        // A purchase that Shopify completed but that failed on our side (a label
        // download that 500s, a crash between the two) leaves the shop charged
        // and the fulfillment created. Buying again would charge twice and be
        // rejected anyway, so re-read the label we already own.
        $recovered = $this->recoverPurchasedLabel($package, $connector);

        if ($recovered) {
            return $recovered;
        }

        // Shopify starts buying the moment the mutation is accepted, so a purchase
        // whose polling timed out or died mid-flight is still running — or already
        // done. Resume that result rather than issuing a second mutation.
        $pendingResultId = $package->metadata['shopify_purchase_result_id'] ?? null;

        if (filled($pendingResultId)) {
            logger()->info('Resuming a Shopify label purchase left in flight', [
                'package_id' => $package->id,
                'purchase_result_id' => $pendingResultId,
            ]);

            return $this->awaitLabel($connector, (string) $pendingResultId, null, $package);
        }

        $totalWeight = $this->totalWeightFor($package, $request, $connector, $fulfillmentOrderId);

        $input = $this->buildPurchaseInput($fulfillmentOrderId, $request, $carrierCode, $serviceCode, $dataSource, $totalWeight);

        $json = $connector->send(new GraphQL(self::PURCHASE_MUTATION, ['input' => $input]))->json();

        $this->assertNoGraphQLErrors($json);

        $payload = $json['data']['shippingLabelPurchase'] ?? [];

        if (! empty($payload['userErrors'])) {
            throw new ShopifyLabelPurchaseException($this->describeErrors($payload['userErrors']));
        }

        $result = $payload['shippingLabelPurchaseResult'] ?? null;
        $resultId = $result['id'] ?? null;

        if (! $resultId) {
            throw new ShopifyLabelPurchaseException('Shopify accepted the label purchase but returned no result to track it with.');
        }

        // Persist before the first poll: from here on Shopify owns a purchase we
        // would otherwise have no way to find again.
        $this->rememberPurchaseResult($package, (string) $resultId);

        return $this->awaitLabel($connector, $resultId, $result, $package);
    }

    /**
     * The fulfillment Shopify holds for this package's label, or null when
     * there is no answer to be had.
     *
     * Null covers three different unknowns deliberately: a shipment that never
     * came from Shopify, a fulfillment order Shopify no longer returns (deleted,
     * or moved to another location), and — the one that matters — an order
     * fulfilled in several shipments, whose other fulfillments belong to other
     * packages. Reading one of those as ours would un-ship a parcel in transit
     * or record another package's delivery against this one, so every caller
     * treats null as "don't know", never as a state.
     *
     * @return array<string, mixed>|null
     *
     * @throws ShopifyLabelPurchaseException
     */
    public function fulfillmentFor(Package $package): ?array
    {
        $dataSource = $this->postageSourceFor($package);
        $fulfillmentOrderId = $this->fulfillmentOrderId($package);

        if (! $dataSource || ! $fulfillmentOrderId || ! $package->tracking_number) {
            return null;
        }

        $connector = ShopifyConnector::fromSettings(
            array_merge($dataSource->settings ?? [], $dataSource->secret_settings ?? [])
        );

        $json = $connector->send(new GraphQL(self::FULFILLMENT_STATE_QUERY, ['id' => $fulfillmentOrderId]))->json();

        $this->assertNoGraphQLErrors($json);

        $fulfillments = $json['data']['fulfillmentOrder']['fulfillments']['nodes'] ?? null;

        if ($fulfillments === null) {
            return null;
        }

        return collect($fulfillments)->first(
            fn (array $fulfillment): bool => ($fulfillment['trackingInfo'][0]['number'] ?? null) === $package->tracking_number
        );
    }

    /**
     * Whether the label PolyBag holds for this package has been voided or
     * cancelled on Shopify's side.
     *
     * Returns false whenever the answer can't be established, so that an
     * ambiguous reply never un-ships a package that is genuinely in transit.
     *
     * @throws ShopifyLabelPurchaseException
     */
    public function isVoidedInShopify(Package $package): bool
    {
        return $this->isVoided($this->fulfillmentFor($package));
    }

    /**
     * Whether an already-fetched fulfillment reports the label as gone.
     *
     * Takes the fulfillment rather than the package so one poll can answer both
     * "was it voided?" and "how far along is it?" — LABEL_VOIDED is the signal
     * for the first and disqualifies the second.
     *
     * @param  array<string, mixed>|null  $fulfillment
     */
    public function isVoided(?array $fulfillment): bool
    {
        if ($fulfillment === null) {
            return false;
        }

        return in_array($fulfillment['displayStatus'] ?? '', self::VOIDED_STATES, true)
            || in_array($fulfillment['status'] ?? '', self::VOIDED_STATES, true);
    }

    /**
     * The fulfillment orders this package's order could still have a label
     * bought against, at the location the shipment was imported for, each with
     * the goods it is for.
     *
     * Exists because a void does not reopen anything. Shopify closes the
     * fulfillment order the voided label was bought against permanently and
     * creates a replacement for the same line items, and there is no edge
     * saying which replaced which — the order's own list of what is still
     * fulfillable is the only way to find it.
     *
     * Deliberately stops at "could": which of these is *this shipment's* work
     * is a question about the goods, and the caller answers it against what the
     * shipment records. Returning identities rather than IDs is what makes that
     * possible without a second request.
     *
     * `null` is "cannot ask" — not a Shopify shipment, no stored order, an order
     * Shopify no longer returns, or one with more fulfillment orders than the
     * query asked for — and must never be read as "none", per the same
     * discipline {@see fulfillmentFor()} keeps. An empty list is a real answer:
     * nothing on this order is fulfillable here any more.
     *
     * @return list<ShopifyFulfillmentOrderIdentity>|null
     *
     * @throws ShopifyLabelPurchaseException
     */
    public function fulfillableFulfillmentOrders(Package $package): ?array
    {
        $dataSource = $this->postageSourceFor($package);

        $package->loadMissing('shipment');
        $orderId = $package->shipment?->metadata['shopify_order_id'] ?? null;
        $locationId = $package->shipment?->metadata['shopify_location_id'] ?? null;

        if (! $dataSource || blank($orderId)) {
            return null;
        }

        $connector = ShopifyConnector::fromSettings(
            array_merge($dataSource->settings ?? [], $dataSource->secret_settings ?? [])
        );

        $json = $connector->send(
            new GraphQL(self::ORDER_FULFILLMENT_ORDERS_QUERY, ['id' => (string) $orderId])
        )->json();

        $this->assertNoGraphQLErrors($json);

        $order = $json['data']['order'] ?? null;

        if ($order === null) {
            return null;
        }

        // A page that did not hold the whole order is not a shorter answer, it
        // is no answer: the replacement may be on the next one, and so may a
        // second candidate that would have made this ambiguous.
        if (data_get($order, 'fulfillmentOrders.pageInfo.hasNextPage', false)) {
            return null;
        }

        return collect($order['fulfillmentOrders']['nodes'] ?? [])
            ->filter(fn (array $node): bool => collect($node['supportedActions'] ?? [])->contains(
                fn (mixed $action): bool => is_array($action) && ($action['action'] ?? null) === 'CREATE_FULFILLMENT',
            ))
            // A fulfillment order assigned somewhere else is somebody else's
            // work, however fulfillable it is: this package is at the location
            // the shipment was imported for.
            ->when(
                filled($locationId),
                fn (Collection $nodes): Collection => $nodes->filter(
                    fn (array $node): bool => data_get($node, 'assignedLocation.location.id') === $locationId,
                ),
            )
            ->map(fn (array $node): ShopifyFulfillmentOrderIdentity => new ShopifyFulfillmentOrderIdentity(
                fulfillmentOrderId: (string) $node['id'],
                orderId: (string) $orderId,
                locationId: data_get($node, 'assignedLocation.location.id'),
                goodsFingerprint: $this->goodsFor($node),
            ))
            ->values()
            ->all();
    }

    /**
     * The goods a fulfillment order node is for, or null when they cannot be
     * read in full — a line-item page that did not fit is evidence of nothing.
     *
     * @param  array<string, mixed>  $node
     */
    private function goodsFor(array $node): ?string
    {
        if (data_get($node, 'lineItems.pageInfo.hasNextPage', false)) {
            return null;
        }

        return $this->goods->forLineItems(data_get($node, 'lineItems.nodes', []), 'remainingQuantity');
    }

    /**
     * The Shopify data source that bought this package's postage.
     *
     * Once a package records channel postage, this is the recorded source or it
     * is nothing. There is deliberately no fallback to the shipment's import
     * source: those are the same record at purchase time, but a shipment can be
     * re-pointed afterwards, and reading a label bought on source A through
     * source B's credentials is exactly the drift the provenance column exists
     * to prevent. Answering "don't know" costs one skipped poll; answering with
     * the wrong shop's fulfillments could un-ship a parcel in transit.
     *
     * An inactive or non-Shopify recorded source is likewise no answer rather
     * than a reason to look elsewhere.
     *
     * Before a package ships there is no provenance to honour, so the purchase
     * path still resolves through the shipment's import source — which is where
     * the fulfillment order it will buy against lives.
     */
    public function postageSourceFor(Package $package): ?DataSource
    {
        if ($package->postage_source !== PostageSource::PostageDataSource) {
            return $this->dataSourceFor($package);
        }

        $package->loadMissing('postageDataSource');

        $source = $package->postageDataSource;

        return ($source && $source->active && $source->source_type === ShopifySource::class)
            ? $source
            : null;
    }

    /**
     * The active Shopify data source a package's shipment was imported from.
     *
     * The binding itself is not Shopify's rule but the general one for channel
     * postage — ADR-0002 decision 9 — so it is asked of `PostageSourceResolver`
     * rather than reimplemented here. All that remains particular to this
     * service is that a source of another driver is no answer: an Amazon
     * account sells postage too, and cannot sell it through this mutation.
     */
    public function dataSourceFor(Package $package): ?DataSource
    {
        $source = $this->postageSourceResolver->channelSourceFor($package);

        return $source?->source_type === ShopifySource::class ? $source : null;
    }

    public function fulfillmentOrderId(Package $package): ?string
    {
        $package->loadMissing('shipment');

        $id = $package->shipment?->metadata['shopify_fulfillment_order_id'] ?? null;

        return filled($id) ? (string) $id : null;
    }

    /**
     * Whether a package can have a Shopify Shipping label bought for it at all.
     */
    public function canPurchaseFor(Package $package): bool
    {
        return $this->dataSourceFor($package) !== null && $this->fulfillmentOrderId($package) !== null;
    }

    /**
     * The total weight to buy this label at, having reconciled the scale
     * against what Shopify will declare in customs.
     *
     * Shopify requires `totalWeight` to be at least the sum of the item weights
     * on its own customs declaration, and reports a violation as
     * `UNKNOWN_ERROR` after the purchase has been enqueued — which also closes
     * the fulfillment order and forces a repoint (issue `18`). So the
     * comparison happens here, before the mutation, where a refusal costs
     * nothing.
     *
     * Three outcomes, and only the middle one changes what is sent:
     *
     * - the box weighs at least as much as the goods, which is the normal case
     *   and the physically expected one — send the scale reading;
     * - it falls short by no more than {@see DECLARED_WEIGHT_TOLERANCE} — send
     *   the declared sum, because the disagreement is measurement rather than
     *   data, and Shopify will not take the reading;
     * - it falls short by more than that — refuse, unless the operator has
     *   already been shown both numbers and asked for the attempt anyway, in
     *   which case the reading is sent unchanged and Shopify is left to say no.
     *
     * Domestic purchases never get here: no customs declaration means no item
     * weights to contradict, which is why this went unseen until the first
     * international label.
     *
     * @throws ShopifyDeclaredWeightException
     */
    private function totalWeightFor(
        Package $package,
        ShipRequest $request,
        ShopifyConnector $connector,
        string $fulfillmentOrderId,
    ): float {
        $scaleWeight = round($request->packageData->weight, 2);

        if (! $request->toAddress->requiresCustomsDeclaration()) {
            return $scaleWeight;
        }

        $declared = $this->declaredItemWeight($connector, $fulfillmentOrderId);

        if ($declared === null || $declared <= $scaleWeight) {
            return $scaleWeight;
        }

        if ($declared - $scaleWeight <= self::DECLARED_WEIGHT_TOLERANCE) {
            // Rounded up, never to nearest: the two decimals Shopify is sent
            // have to stay at or above the sum they are standing in for, and a
            // sum reached through a unit conversion lands just under it as
            // often as just over.
            $nudged = ceil(round($declared * 100, 6)) / 100;

            logger()->info('Buying a Shopify label at its declared item weight rather than the scale reading', [
                'package_id' => $package->id,
                'scale_weight' => $scaleWeight,
                'declared_weight' => $nudged,
            ]);

            return $nudged;
        }

        if (! $request->overrideDeclaredWeight) {
            throw new ShopifyDeclaredWeightException($declared, $scaleWeight);
        }

        logger()->warning('Attempting a Shopify purchase below its declared item weight at the operator\'s request', [
            'package_id' => $package->id,
            'scale_weight' => $scaleWeight,
            'declared_weight' => $declared,
        ]);

        return $scaleWeight;
    }

    /**
     * What Shopify's catalogue says this fulfillment order's goods weigh, in
     * pounds, or null when that cannot be established.
     *
     * Null is "cannot ask", never "nothing" — a fulfillment order Shopify no
     * longer returns, or a line-item page that did not fit — and the caller
     * proceeds on it rather than withholding, keeping the same discipline
     * {@see fulfillableFulfillmentOrders()} does. Refusing a purchase on the
     * strength of an unread page would ground shipments over a paging limit.
     *
     * **Alone among this class's reads, this one does not throw on a GraphQL
     * error.** Every other query here is load-bearing: without it there is no
     * label, or no way to find one already bought. This one is advisory — it
     * decides whether to *withhold* a purchase — so a failure to read it must
     * cost at most the check itself. Throwing would turn a missing
     * `read_products` grant, or a throttle, into a failed purchase on every
     * international label: worse than the defect the check exists to prevent,
     * and failing in the same place, after the box is taped shut. Errors are
     * logged and whatever data came back is used, which for a denied
     * `lineItem.variant` traversal is still the order-time snapshot.
     *
     * Per line item the live catalogue weight is preferred and the snapshot is
     * the fallback. A line item with neither contributes nothing, which is what
     * Shopify itself declares for it.
     */
    private function declaredItemWeight(ShopifyConnector $connector, string $fulfillmentOrderId): ?float
    {
        $json = $connector->send(
            new GraphQL(self::DECLARED_ITEM_WEIGHT_QUERY, ['id' => $fulfillmentOrderId])
        )->json();

        if (! empty($json['errors'])) {
            logger()->warning('Shopify would not fully report what it will declare in customs', [
                'fulfillment_order_id' => $fulfillmentOrderId,
                'errors' => $json['errors'],
                'hint' => 'Reading the live catalogue weight needs the read_products scope; '
                    .'without it the order-time snapshot is used instead.',
            ]);
        }

        $lineItems = $json['data']['fulfillmentOrder']['lineItems'] ?? null;

        if ($lineItems === null || data_get($lineItems, 'pageInfo.hasNextPage', false)) {
            return null;
        }

        return collect($lineItems['nodes'] ?? [])->sum(function (array $node): float {
            $live = ShopifySource::poundsFrom(
                data_get($node, 'lineItem.variant.inventoryItem.measurement.weight')
            );

            $unitWeight = $live ?? ShopifySource::poundsFrom($node['weight'] ?? null);

            return ($unitWeight ?? 0.0) * (int) ($node['remainingQuantity'] ?? 0);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPurchaseInput(
        string $fulfillmentOrderId,
        ShipRequest $request,
        ?string $carrierCode,
        ?string $serviceCode,
        DataSource $dataSource,
        ?float $totalWeight = null,
    ): array {
        $package = $request->packageData;
        $totalWeight ??= round($package->weight, 2);

        // Shopify rejects a shipping date in the past, and a date-only ship date
        // resolves to midnight — which is already past by the time packing starts.
        $shipDate = $request->shipDate?->toDateTime();
        $shippingDatetime = ($shipDate && $shipDate > now())
            ? $request->shipDate->toIso8601String()
            : now()->addMinutes(5)->toIso8601String();

        $input = [
            'fulfillmentOrderId' => $fulfillmentOrderId,
            'shippingDatetime' => $shippingDatetime,
            'notifyCustomer' => (bool) ($dataSource->settings['notify_customer'] ?? false),
            'packageInfo' => [
                'customPackage' => [
                    'dimensions' => [
                        'length' => round($package->length, 2),
                        'width' => round($package->width, 2),
                        'height' => round($package->height, 2),
                        'unit' => 'INCHES',
                    ],
                    // PolyBag weighs the packed box, so the empty-package weight
                    // Shopify would otherwise add is already included in the total.
                    'weight' => ['value' => 0.0, 'unit' => 'POUNDS'],
                    'type' => 'BOX',
                ],
            ],
            'totalWeight' => ['value' => $totalWeight, 'unit' => 'POUNDS'],
            'originAddress' => $this->mailingAddress($request->fromAddress),
        ];

        if ($carrierCode !== null && $serviceCode !== null) {
            $input['preferredRateSelection'] = [
                'carrierCode' => $carrierCode,
                'serviceCode' => $serviceCode,
            ];
        }

        return $input;
    }

    /**
     * @return array<string, string>
     */
    private function mailingAddress(AddressData $address): array
    {
        return array_filter([
            'firstName' => $address->firstName,
            'lastName' => $address->lastName,
            'company' => $address->company,
            'address1' => $address->streetAddress,
            'address2' => $address->streetAddress2,
            'city' => $address->city,
            'provinceCode' => $address->stateOrProvince,
            'zip' => $address->postalCode,
            'countryCode' => $address->country,
            'phone' => $address->phone,
        ], fn (?string $value): bool => filled($value));
    }

    /**
     * Poll the purchase result until Shopify finishes buying the label.
     *
     * @param  array<string, mixed>|null  $initialResult
     *
     * @throws ShopifyLabelPurchaseException
     */
    private function awaitLabel(ShopifyConnector $connector, string $resultId, ?array $initialResult, Package $package): ShopifyPurchasedLabel
    {
        $attempts = (int) config('services.shopify.label_poll_attempts', 20);
        $intervalMs = (int) config('services.shopify.label_poll_interval_ms', 1500);

        $result = $initialResult;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $status = $result['status'] ?? null;

            if ($status === 'PURCHASE_FAILED') {
                // Terminal: no label was bought, so the next attempt is free to
                // start a fresh purchase rather than resuming this dead result.
                $this->forgetPurchaseResult($package);

                throw new ShopifyLabelPurchaseException(
                    empty($result['errors'])
                        ? 'Shopify could not buy the label and gave no reason.'
                        : $this->describeErrors($result['errors'])
                );
            }

            // The mutation's own result never carries labels, so a PURCHASED
            // status is only actionable once a polled result brings them along.
            if ($status === 'PURCHASED' && isset($result['shippingLabels'])) {
                return $this->buildLabel($result, $package);
            }

            if ($attempt > 0) {
                usleep($intervalMs * 1000);
            }

            $json = $connector->send(new GraphQL(self::PURCHASE_STATUS_QUERY, ['id' => $resultId]))->json();
            $this->assertNoGraphQLErrors($json);

            $result = $json['data']['node'] ?? null;

            if (! $result) {
                throw new ShopifyLabelPurchaseException('Shopify stopped reporting on the label purchase before it finished.');
            }
        }

        // The result ID stays on the package deliberately: the purchase is still
        // running at Shopify, and the next attempt resumes it instead of buying
        // a second label.
        throw new ShopifyLabelPurchaseException(
            'Shopify is still buying the label. Try again in a moment — this will pick up the purchase already in progress rather than buying another.'
        );
    }

    /**
     * Re-read a label a previous attempt already bought.
     *
     * Keyed off `shopify_shipping_label_id` surviving on an unshipped package,
     * which only happens when a purchase settled at Shopify and then failed
     * before the package was marked shipped. The void synchronizer clears the
     * key when it un-ships a package, so a re-ship after a Shopify-side void
     * buys a new label rather than resurrecting the voided one.
     *
     * @throws ShopifyLabelPurchaseException
     */
    private function recoverPurchasedLabel(Package $package, ShopifyConnector $connector): ?ShopifyPurchasedLabel
    {
        $labelId = $package->metadata['shopify_shipping_label_id'] ?? null;

        if (! filled($labelId)) {
            return null;
        }

        logger()->info('Recovering a Shopify label bought by an earlier attempt', [
            'package_id' => $package->id,
            'shipping_label_id' => $labelId,
        ]);

        $json = $connector->send(new GraphQL(self::SHIPPING_LABEL_QUERY, ['id' => $labelId]))->json();

        $this->assertNoGraphQLErrors($json);

        $label = $json['data']['shippingLabel'] ?? null;

        if (! $label) {
            // Never fall through to a fresh purchase here. We know a label was
            // bought; buying another would charge the shop twice for one parcel.
            throw new ShopifyLabelPurchaseException(
                "A Shopify label ({$labelId}) was already bought for this package but can no longer be read. "
                .'Check the order in Shopify before shipping it again.'
            );
        }

        return $this->buildLabelFromNode($label);
    }

    /**
     * @param  array<string, mixed>  $result
     *
     * @throws ShopifyLabelPurchaseException
     */
    private function buildLabel(array $result, Package $package): ShopifyPurchasedLabel
    {
        $label = $result['shippingLabels'][0] ?? null;

        if (! $label) {
            throw new ShopifyLabelPurchaseException('Shopify reported the label as purchased but returned no label.');
        }

        // Record the purchase before downloading anything. Shopify has charged
        // for this label already; if the download fails, the package stays
        // unshipped but the label ID survives, so the next attempt recovers it
        // instead of buying a second one.
        $this->rememberPurchase($package, $label);

        return $this->buildLabelFromNode($label);
    }

    /**
     * Record the in-flight purchase so it can be resumed rather than repeated.
     */
    private function rememberPurchaseResult(Package $package, string $resultId): void
    {
        $package->metadata = array_merge($package->metadata ?? [], [
            'shopify_purchase_result_id' => $resultId,
        ]);

        $package->save();
    }

    private function forgetPurchaseResult(Package $package): void
    {
        $package->metadata = collect($package->metadata ?? [])
            ->except(['shopify_purchase_result_id'])
            ->all();

        $package->save();
    }

    /**
     * @param  array<string, mixed>  $label
     */
    private function rememberPurchase(Package $package, array $label): void
    {
        $documents = collect($label['shippingDocuments'] ?? []);

        // The label ID supersedes the purchase-result ID: once a label exists,
        // recovery reads the label directly and the result must not be resumed
        // again. Keeping only one live marker keeps the states unambiguous.
        $package->metadata = collect(array_merge($package->metadata ?? [], array_filter([
            'shopify_shipping_label_id' => $label['id'] ?? null,
            'shopify_label_document_url' => $documents->firstWhere('documentType', 'LABEL')['url'] ?? null,
            'shopify_customs_form_url' => $documents->firstWhere('documentType', 'CUSTOMS_FORM')['url'] ?? null,
        ], fn (?string $value): bool => filled($value))))
            ->except(['shopify_purchase_result_id'])
            ->all();

        $package->save();
    }

    /**
     * @param  array<string, mixed>  $label
     *
     * @throws ShopifyLabelPurchaseException
     */
    private function buildLabelFromNode(array $label): ShopifyPurchasedLabel
    {
        $documents = collect($label['shippingDocuments'] ?? []);
        $labelDocument = $documents->firstWhere('documentType', 'LABEL');

        if (! $labelDocument) {
            throw new ShopifyLabelPurchaseException('Shopify returned the purchase without a label document to print.');
        }

        $customsDocument = $documents->firstWhere('documentType', 'CUSTOMS_FORM');

        return new ShopifyPurchasedLabel(
            shippingLabelId: (string) $label['id'],
            trackingNumber: $label['trackingInfo']['number'] ?? null,
            trackingCompany: $label['trackingInfo']['company'] ?? null,
            labelData: $this->download($labelDocument['url'] ?? null),
            // Reported, never requested: `ShippingLabelPurchaseInput` has no
            // format field. `ShippingEnumsFileFormat` is PDF or ZPL, but the
            // shop's label format setting selects a paper size (Thermal 4x6,
            // Letter, A4), all three are PDF, and it is applied when the admin
            // renders for printing rather than to the document behind this URL.
            // So nothing merchant-facing reaches the ZPL value, and what we
            // download is a 4x6 PDF whatever that setting says. PDF is what a
            // label with no stated format has to be.
            labelFormat: strtolower((string) ($labelDocument['format'] ?? 'pdf')),
            customsFormUrl: $customsDocument['url'] ?? null,
            labelDocumentUrl: $labelDocument['url'] ?? null,
        );
    }

    private function download(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $response = Http::timeout(30)->get($url);

        if (! $response->successful()) {
            throw new ShopifyLabelPurchaseException(
                "Shopify bought the label but it could not be downloaded (HTTP {$response->status()}). Reprint it from the Shopify admin."
            );
        }

        return base64_encode($response->body());
    }

    /**
     * @param  array<string, mixed>  $json
     *
     * @throws ShopifyLabelPurchaseException
     */
    private function assertNoGraphQLErrors(array $json): void
    {
        if (empty($json['errors'])) {
            return;
        }

        $messages = array_map(
            fn (array $error): string => (string) ($error['message'] ?? 'Unknown GraphQL error'),
            $json['errors'],
        );

        throw new ShopifyLabelPurchaseException('Shopify GraphQL error: '.implode('; ', $messages));
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    private function describeErrors(array $errors): string
    {
        return implode('; ', array_map(
            fn (array $error): string => (string) ($error['message'] ?? $error['code'] ?? 'Unknown error'),
            $errors,
        ));
    }
}
