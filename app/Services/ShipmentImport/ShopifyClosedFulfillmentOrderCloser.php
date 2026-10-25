<?php

namespace App\Services\ShipmentImport;

use App\DataTransferObjects\ShipmentImport\ShopifyFulfillmentOrderIdentity;
use App\DataTransferObjects\ShipmentImport\SourceClosureResult;
use App\DataTransferObjects\ShipmentImport\SourceOrderReport;
use App\DataTransferObjects\ShipmentImport\SourceParcel;
use App\Enums\ShipmentStatus;
use App\Enums\ShipmentStatusOrigin;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Http\Integrations\Shopify\ShopifyConnector;
use App\Models\DataSource;
use App\Models\DataSourceLocation;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Closes the Shipments whose Shopify fulfillment order the import no longer
 * offers (`order-reconciliation/06`).
 *
 * The import reads open fulfillment orders only, so one cancelled, fulfilled
 * by other software or by hand, or moved to a location the connection ignores
 * leaves the feed or is skipped. Each such Shipment is looked up, many to a
 * `nodes(ids:)` request, and {@see SourceClosureApplier} closes it if Shopify
 * has.
 *
 * A fulfillment order moved whole keeps its ID (`fulfillmentOrderMove`,
 * API 2026-07), so the import fetches it at its new location; when that
 * location is ignored, it is a candidate here too.
 *
 * A run that searched only for changes (`order-reconciliation/11`) says
 * nothing about a fulfillment order it did not return, so only the ones it
 * returned and did not import are candidates: closed, cancelled, on hold, or
 * at an ignored location. The lookup reads them again rather than the search
 * selecting the closing fields too, which would take its cost past Shopify's
 * limit for one query.
 */
class ShopifyClosedFulfillmentOrderCloser
{
    /**
     * `nodes(ids:)` takes at most 250 IDs. Its cost does not grow with them:
     * 14 points for 1 or 246 on the development store (2026-10-13), against
     * a 20,000-point bucket.
     */
    public const BATCH_SIZE = 250;

    private const FULFILLMENT_ORDER_PREFIX = 'gid://shopify/FulfillmentOrder/';

    private const LOOKUP_QUERY = <<<'GRAPHQL'
        query ClosedFulfillmentOrders($ids: [ID!]!) {
          nodes(ids: $ids) {
            ... on FulfillmentOrder {
              id status
              assignedLocation { location { id } }
              order { cancelledAt }
              fulfillments(first: 10) {
                pageInfo { hasNextPage endCursor }
                nodes { status trackingInfo { company number } }
              }
            }
          }
        }
        GRAPHQL;

    /**
     * The rest of one fulfillment order's fulfillments, when the lookup's first
     * page did not hold them all. `trackingInfo` takes no `first`: given one,
     * Shopify truncates the list.
     */
    private const FULFILLMENTS_QUERY = <<<'GRAPHQL'
        query FulfillmentOrderFulfillments($id: ID!, $cursor: String) {
          fulfillmentOrder(id: $id) {
            fulfillments(first: 50, after: $cursor) {
              pageInfo { hasNextPage endCursor }
              nodes { status trackingInfo { company number } }
            }
          }
        }
        GRAPHQL;

    /** Fulfillment statuses that shipped nothing. */
    private const DEAD_FULFILLMENT_STATUSES = ['CANCELLED', 'ERROR', 'FAILURE'];

    public function __construct(
        private readonly SourceClosureApplier $applier,
    ) {}

    /**
     * @param  Collection<int, ShopifyFulfillmentOrderIdentity>  $identities  every fulfillment order the run read
     * @param  list<string>|null  $answeredIds  the fulfillment orders a search for changes returned; null after a full read
     */
    public function close(DataSource $dataSource, ShopifyConnector $connector, Collection $identities, ?array $answeredIds = null): SourceClosureResult
    {
        $ignoredLocationIds = DataSourceLocation::query()
            ->where('data_source_id', $dataSource->id)
            ->whereNotNull('ignored_at')
            ->pluck('external_id')
            ->flip();

        $candidateIds = $this->candidateIds($dataSource, $identities, $ignoredLocationIds, $answeredIds);

        $closed = 0;
        $errors = [];

        foreach (array_chunk($candidateIds, self::BATCH_SIZE) as $ids) {
            // Loaded a batch at a time, never all at once: a connection's
            // open Shipments number as many as the store's open orders.
            $batch = $this->closable($dataSource, $answeredIds)
                ->whereIn('source_record_id', $ids)
                ->get()
                ->keyBy('source_record_id');

            if ($batch->isEmpty()) {
                continue;
            }

            try {
                $nodes = $this->lookUp($connector, $batch->keys()->all());
            } catch (\Exception $e) {
                $errors[] = 'Could not check '.$batch->count().' Shopify fulfillment '
                    .($batch->count() === 1 ? 'order' : 'orders').' that left the import; they are checked again next run: '.$e->getMessage();

                continue;
            }

            foreach ($nodes as $node) {
                $shipment = $batch->get((string) ($node['id'] ?? ''));

                if ($shipment === null) {
                    continue;
                }

                // Whether nothing shipped, or only PolyBag's parcels did, can
                // be answered only from every fulfillment.
                try {
                    $node = $this->withAllFulfillments($connector, $node);
                } catch (\Exception $e) {
                    $errors[] = "Could not read every fulfillment of shipment {$shipment->shipment_reference}'s Shopify fulfillment order; it is checked again next run: ".$e->getMessage();

                    continue;
                }

                $report = $this->report($node, $ignoredLocationIds);

                if ($report !== null && $this->applier->apply($shipment, $report)) {
                    $closed++;
                }
            }
        }

        return new SourceClosureResult($closed, $errors);
    }

    /**
     * The fulfillment orders of this connection's open Shipments the run did
     * not fetch, or fetched at a location it ignores. A fulfillment order the
     * repointer moved a Shipment onto was fetched, so that Shipment is not a
     * candidate.
     *
     * An identity's location is the Shopify location its row's
     * `source_location` names, so the identities answer this as the rows
     * would.
     *
     * @param  Collection<int, ShopifyFulfillmentOrderIdentity>  $identities
     * @param  Collection<string, int>  $ignoredLocationIds
     * @param  list<string>|null  $answeredIds
     * @return list<string>
     */
    private function candidateIds(DataSource $dataSource, Collection $identities, Collection $ignoredLocationIds, ?array $answeredIds): array
    {
        if ($answeredIds === []) {
            return [];
        }

        $offered = $identities
            ->reject(fn (ShopifyFulfillmentOrderIdentity $identity): bool => $ignoredLocationIds->has((string) $identity->locationId))
            ->mapWithKeys(fn (ShopifyFulfillmentOrderIdentity $identity): array => [$identity->fulfillmentOrderId => true]);

        return $this->closable($dataSource, $answeredIds)
            ->pluck('source_record_id')
            ->reject(fn (string $id): bool => $offered->has($id))
            ->values()
            ->all();
    }

    /**
     * This connection's open Shipments that a lookup may close. One a manager
     * reopened never is (decision 7).
     *
     * @param  list<string>|null  $answeredIds
     * @return Builder<Shipment>
     */
    private function closable(DataSource $dataSource, ?array $answeredIds): Builder
    {
        return Shipment::query()
            ->where('data_source_id', $dataSource->id)
            ->where('status', ShipmentStatus::Open)
            ->where(fn (Builder $query) => $query
                ->whereNull('status_origin')
                ->orWhere('status_origin', '!=', ShipmentStatusOrigin::Manager))
            ->where('source_record_id', 'like', self::FULFILLMENT_ORDER_PREFIX.'%')
            ->when($answeredIds !== null, fn (Builder $query) => $query->whereIn('source_record_id', $answeredIds));
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function lookUp(ShopifyConnector $connector, array $ids): array
    {
        $json = $connector->send(new GraphQL(self::LOOKUP_QUERY, ['ids' => $ids]))->json();

        if (! empty($json['errors'])) {
            throw new RuntimeException('Shopify GraphQL error: '.json_encode($json['errors']));
        }

        $nodes = $json['data']['nodes'] ?? null;

        if (! is_array($nodes)) {
            throw new RuntimeException('Shopify returned no fulfillment orders.');
        }

        // A deleted fulfillment order comes back null: no answer, so no change.
        return array_values(array_filter($nodes, 'is_array'));
    }

    /**
     * The node with every fulfillment, fetching the pages after the lookup's
     * first.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function withAllFulfillments(ShopifyConnector $connector, array $node): array
    {
        $connection = $node['fulfillments'] ?? [];
        $fulfillments = $connection['nodes'] ?? [];
        $cursor = ($connection['pageInfo']['hasNextPage'] ?? false) ? ($connection['pageInfo']['endCursor'] ?? null) : null;

        while ($cursor !== null) {
            $json = $connector->send(new GraphQL(self::FULFILLMENTS_QUERY, ['id' => $node['id'], 'cursor' => $cursor]))->json();

            if (! empty($json['errors'])) {
                throw new RuntimeException('Shopify GraphQL error: '.json_encode($json['errors']));
            }

            $connection = $json['data']['fulfillmentOrder']['fulfillments'] ?? null;

            if (! is_array($connection)) {
                throw new RuntimeException('Shopify returned no fulfillments.');
            }

            array_push($fulfillments, ...($connection['nodes'] ?? []));
            $cursor = ($connection['pageInfo']['hasNextPage'] ?? false) ? ($connection['pageInfo']['endCursor'] ?? null) : null;
        }

        $node['fulfillments'] = ['nodes' => $fulfillments];

        return $node;
    }

    /**
     * What Shopify reported, read by the table in `order-reconciliation/06`.
     * Null when it reports nothing that closes the Shipment.
     *
     * @param  array<string, mixed>  $node
     * @param  Collection<string, int>  $ignoredLocationIds
     */
    private function report(array $node, Collection $ignoredLocationIds): ?SourceOrderReport
    {
        $status = (string) ($node['status'] ?? '');

        if (filled($node['order']['cancelledAt'] ?? null)) {
            return SourceOrderReport::cancelled('Shopify reported the order cancelled');
        }

        if ($status === 'CANCELLED') {
            return SourceOrderReport::cancelled('Shopify reported the fulfillment order cancelled');
        }

        if ($status === 'CLOSED') {
            $parcels = $this->liveParcels($node);

            return $parcels === []
                ? SourceOrderReport::cancelled('Shopify closed the fulfillment order with nothing shipped')
                : SourceOrderReport::shipped('Shopify reported the fulfillment order fulfilled', $parcels);
        }

        if ($ignoredLocationIds->has((string) ($node['assignedLocation']['location']['id'] ?? ''))) {
            return SourceOrderReport::cancelled('Shopify moved the fulfillment order to a location this connection ignores');
        }

        return null;
    }

    /**
     * A parcel for each tracking number on a live fulfillment, and one with
     * no number for a live fulfillment that has none.
     *
     * @param  array<string, mixed>  $node
     * @return list<SourceParcel>
     */
    private function liveParcels(array $node): array
    {
        $parcels = [];

        foreach ($node['fulfillments']['nodes'] ?? [] as $fulfillment) {
            if (in_array($fulfillment['status'] ?? null, self::DEAD_FULFILLMENT_STATUSES, true)) {
                continue;
            }

            $trackingInfo = array_filter(
                $fulfillment['trackingInfo'] ?? [],
                fn (mixed $info): bool => is_array($info) && filled($info['number'] ?? null),
            );

            if ($trackingInfo === []) {
                $parcels[] = new SourceParcel(self::filled($fulfillment['trackingInfo'][0]['company'] ?? null), null);

                continue;
            }

            foreach ($trackingInfo as $info) {
                $parcels[] = new SourceParcel(self::filled($info['company'] ?? null), trim((string) $info['number']));
            }
        }

        return $parcels;
    }

    private static function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
