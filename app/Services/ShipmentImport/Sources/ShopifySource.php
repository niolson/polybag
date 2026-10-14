<?php

namespace App\Services\ShipmentImport\Sources;

use App\Contracts\ClosesRecordsClosedAtSource;
use App\Contracts\DataSourceInterface;
use App\Contracts\ExportDestinationInterface;
use App\Contracts\ReconcilesSupersededRecords;
use App\DataTransferObjects\ShipmentImport\ShopifyFulfillmentOrderIdentity;
use App\DataTransferObjects\ShipmentImport\ShopifyMetafieldReference;
use App\DataTransferObjects\ShipmentImport\SourceClosureResult;
use App\Enums\DutiesTerms;
use App\Enums\RecipientTaxIdType;
use App\Enums\ShopifyMetafieldOwner;
use App\Exceptions\PermanentExportException;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Http\Integrations\Shopify\ShopifyConnector;
use App\Models\Carrier;
use App\Models\DataSource;
use App\Models\DataSourceLocation;
use App\Services\ShipmentImport\ShopifyClosedFulfillmentOrderCloser;
use App\Services\ShipmentImport\ShopifyFulfillmentOrderRepointer;
use App\Services\ShopifyFulfillmentOrderActivationService;
use App\Services\ShopifyGoodsFingerprint;
use App\Services\ShopifyShippingLabelService;
use App\Support\Gtin;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;

class ShopifySource implements ClosesRecordsClosedAtSource, DataSourceInterface, ExportDestinationInterface, ReconcilesSupersededRecords
{
    private const ACCESS_SCOPES_QUERY = <<<'GRAPHQL'
        query AccessScopes {
          currentAppInstallation {
            accessScopes { handle }
          }
        }
        GRAPHQL;

    private const LOCATIONS_QUERY = <<<'GRAPHQL'
        query ActiveLocations($cursor: String) {
          locations(first: 250, after: $cursor) {
            pageInfo { hasNextPage endCursor }
            nodes {
              id name isActive
              address {
                address1 address2 city provinceCode zip countryCode
              }
            }
          }
        }
        GRAPHQL;

    private const METAFIELD_DEFINITIONS_QUERY = <<<'GRAPHQL'
        query MetafieldDefinitions($ownerType: MetafieldOwnerType!, $cursor: String) {
          metafieldDefinitions(first: 250, after: $cursor, ownerType: $ownerType) {
            pageInfo { hasNextPage endCursor }
            nodes {
              name namespace key
              type { name }
            }
          }
        }
        GRAPHQL;

    private const SAMPLED_VARIANT_METAFIELDS_QUERY = <<<'GRAPHQL'
        query SampledVariantMetafields {
          productVariants(first: 25, sortKey: ID, reverse: true) {
            nodes {
              metafields(first: 50) {
                nodes { namespace key type }
              }
            }
          }
        }
        GRAPHQL;

    /**
     * Metafield types a part number can be read from as text. A list type
     * yields its first entry.
     */
    public const TEXT_METAFIELD_TYPES = [
        'single_line_text_field',
        'multi_line_text_field',
        'number_integer',
        'list.single_line_text_field',
        'list.multi_line_text_field',
        'list.number_integer',
    ];

    private array $config;

    private ShopifyConnector $connector;

    private ShopifyGoodsFingerprint $goods;

    /** @var array<string, array> Cached order data keyed by source record ID */
    private array $orderCache = [];

    /**
     * The fulfillment orders a search for changes returned, open or not, for
     * {@see reconcileSupersededRecords()} and
     * {@see closeRecordsClosedAtSource()}: what this run can say anything
     * about. Null after a full read, which can say something about every one.
     *
     * @var list<string>|null
     */
    private ?array $answeredFulfillmentOrderIds = null;

    /**
     * What {@see closeRecordsClosedAtSource()} records on the connection once
     * the run completes: how far it read. Null when it records nothing.
     *
     * @var array<string, mixed>|null
     */
    private ?array $pendingReadState = null;

    private const CARRIER_MAP = [
        'USPS' => Carrier::USPS,
        'FedEx' => Carrier::FEDEX,
        'UPS' => Carrier::UPS,
        'DHL' => 'DHL Express',
    ];

    private const RETRYABLE_GRAPHQL_ERROR_CODES = [
        'THROTTLED',
        'INTERNAL_SERVER_ERROR',
        'SERVICE_UNAVAILABLE',
    ];

    /**
     * What the import reads of a fulfillment order, whichever query fetched
     * it. `{{partNumberSelection}}` is filled by {@see lineItemQuery()}.
     */
    private const IMPORTED_FULFILLMENT_ORDER_FRAGMENT = <<<'GRAPHQL'
        fragment ImportedFulfillmentOrder on FulfillmentOrder {
          id status
          order {
            id name email
            shippingAddress { provinceCode zip }
            originalTotalDutiesSet { shopMoney { amount } }
            localizedFields(first: 10) { nodes { key value } }
          }
          destination {
            firstName lastName company address1 address2
            city province zip countryCode phone
          }
          assignedLocation {
            name address1 address2 city province zip countryCode
            location {
              id name isActive
              address { address1 address2 city provinceCode zip countryCode }
            }
          }
          lineItems(first: 40) {
            pageInfo { hasNextPage endCursor }
            nodes {
              id sku productTitle remainingQuantity requiresShipping
              weight { unit value }
              variant {
                id
                barcodes(first: 5) { nodes { type value } }
                inventoryItem { harmonizedSystemCode countryCodeOfOrigin }
                {{partNumberSelection}}
              }
              lineItem { originalUnitPriceSet { shopMoney { amount } } }
            }
          }
        }
        GRAPHQL;

    /**
     * Every open fulfillment order. 250 to a page, Shopify's most, requests
     * 926 points with a part-number metafield and `barcodes(first: 5)`, on
     * the development store (2026-10-14). `barcodes(first: 10)` requests
     * 1,003 and Shopify refuses it: 1,000 is the most one query may request.
     */
    private const FULFILLMENT_ORDERS_QUERY = <<<'GRAPHQL'
        query FulfillmentOrders($cursor: String{{partNumberVariables}}) {
          fulfillmentOrders(first: 250, after: $cursor, includeClosed: false) {
            pageInfo { hasNextPage endCursor }
            nodes { ...ImportedFulfillmentOrder }
          }
        }
        GRAPHQL;

    /**
     * The orders updated since a time, with the ID and status of each of
     * their fulfillment orders, closed ones included: `Order.fulfillmentOrders`
     * returns them without being asked. A fulfillment order's own `updatedAt`
     * moves only when its status or location changes, and an order's moves on
     * every change the import cares about (`order-reconciliation/11`).
     *
     * Kept to IDs because selecting the import's fields under two nested
     * connections exceeds Shopify's 1,000-point limit on one query; 250 orders
     * here request 266 points, and {@see FULFILLMENT_ORDER_NODES_QUERY} reads
     * the ones it imports.
     */
    private const CHANGED_ORDERS_QUERY = <<<'GRAPHQL'
        query ChangedOrders($query: String!, $cursor: String) {
          orders(first: 250, after: $cursor, sortKey: UPDATED_AT, query: $query) {
            pageInfo { hasNextPage endCursor }
            nodes {
              id
              fulfillmentOrders(first: 50) {
                pageInfo { hasNextPage endCursor }
                nodes { id status }
              }
            }
          }
        }
        GRAPHQL;

    /** The rest of an order's fulfillment orders, past the first 50. */
    private const ORDER_FULFILLMENT_ORDERS_QUERY = <<<'GRAPHQL'
        query OrderFulfillmentOrders($id: ID!, $cursor: String) {
          order(id: $id) {
            fulfillmentOrders(first: 50, after: $cursor) {
              pageInfo { hasNextPage endCursor }
              nodes { id status }
            }
          }
        }
        GRAPHQL;

    /** Fulfillment orders by ID, as the import reads them. */
    private const FULFILLMENT_ORDER_NODES_QUERY = <<<'GRAPHQL'
        query ChangedFulfillmentOrders($ids: [ID!]!{{partNumberVariables}}) {
          nodes(ids: $ids) {
            ...ImportedFulfillmentOrder
          }
        }
        GRAPHQL;

    /** `nodes(ids:)` takes at most 250 IDs. */
    private const NODES_BATCH_SIZE = 250;

    /**
     * Without it, Shopify answers for no order older than 60 days by order
     * search, so a connection lacking it is read in full every run.
     */
    public const ALL_ORDERS_SCOPE = 'read_all_orders';

    /** Statuses the import imports. */
    private const IMPORTED_STATUSES = ['OPEN', 'IN_PROGRESS'];

    /** How often a scheduled import reads every open fulfillment order, not only what changed. */
    public const FULL_READ_HOURS = 24;

    /**
     * How far back of where the last run read through the next one starts.
     * Shopify returned every change within 1.5 seconds on the development
     * store, but `updated_at` compares whole seconds and `>` is strict, so a
     * change in the second a search started would otherwise be missed.
     */
    private const READ_OVERLAP_SECONDS = 120;

    /** Requests a throttled read retries before it gives up. */
    private const THROTTLE_ATTEMPTS = 10;

    /** The longest one wait for Shopify's bucket to refill. */
    private const MAX_THROTTLE_WAIT_SECONDS = 30;

    private const FULFILLMENT_ORDER_ITEMS_QUERY = <<<'GRAPHQL'
        query FulfillmentOrderItems($id: ID!, $cursor: String{{partNumberVariables}}) {
          fulfillmentOrder(id: $id) {
            lineItems(first: 250, after: $cursor) {
              pageInfo { hasNextPage endCursor }
              nodes {
                id sku productTitle remainingQuantity requiresShipping
                weight { unit value }
                variant {
                  id
                  barcodes(first: 5) { nodes { type value } }
                  inventoryItem { harmonizedSystemCode countryCodeOfOrigin }
                  {{partNumberSelection}}
                }
                lineItem { originalUnitPriceSet { shopMoney { amount } } }
              }
            }
          }
        }
        GRAPHQL;

    private const FULFILLMENT_MUTATION = <<<'GRAPHQL'
        mutation CreateFulfillment($fulfillment: FulfillmentInput!) {
          fulfillmentCreate(fulfillment: $fulfillment) {
            fulfillment { id status trackingInfo { company number url } }
            userErrors { field message }
          }
        }
        GRAPHQL;

    private const FULFILLMENT_CANCEL_MUTATION = <<<'GRAPHQL'
        mutation CancelFulfillment($id: ID!) {
          fulfillmentCancel(id: $id) {
            fulfillment { id status }
            userErrors { field message }
          }
        }
        GRAPHQL;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connector = ShopifyConnector::fromSettings($config);
        $this->goods = app(ShopifyGoodsFingerprint::class);
    }

    public function validateConfiguration(): void
    {
        if (empty($this->config['shop_domain'] ?? null)) {
            throw new InvalidArgumentException('Shopify shop domain is not configured for this source.');
        }

        $hasOwnToken = filled($this->config['oauth_access_token'] ?? null);
        $hasOwnCredentials = filled($this->config['client_id'] ?? null)
            && filled($this->config['client_secret'] ?? null);

        if (! $hasOwnToken && ! $hasOwnCredentials) {
            throw new InvalidArgumentException('Shopify API credentials are not configured for this source.');
        }

        if (empty($this->config['channel_name'])) {
            throw new InvalidArgumentException('Shopify channel name is not configured.');
        }
    }

    public function fetchShipments(): Collection
    {
        if (! ($this->config['fulfillment_order_import_enabled'] ?? false)) {
            throw new DomainException(
                'Shopify shipment import is not ready. Synchronize and map Shopify locations, then activate fulfillment-order imports.'
            );
        }

        $this->orderCache = [];
        $this->answeredFulfillmentOrderIds = null;
        $this->pendingReadState = null;

        $scopes = $this->assertImportScopes();

        return $this->readFulfillmentOrders(in_array(self::ALL_ORDERS_SCOPE, $scopes, true));
    }

    /**
     * The fulfillment orders a run imports (`order-reconciliation/11`).
     *
     * A connection that has read through searches for the orders updated
     * since, and imports their open fulfillment orders: one request when
     * nothing changed, however many orders are open. The closed ones it
     * returns are left to {@see closeRecordsClosedAtSource()}.
     *
     * Every open fulfillment order is read instead on the first run, once a
     * day ({@see FULL_READ_HOURS}), when a location's mapping changed after
     * the last run started (see {@see DataSourceLocation}), on a connection
     * pointed at another shop, and on every run of a connection without
     * `read_all_orders`: without it Shopify keeps orders older than 60 days
     * out of the search, and out of `order(id:)` too, so a backorder older
     * than that would never be seen to change. The full read reaches them
     * through `fulfillmentOrders`, which that window does not apply to. That read is `06`'s: a Shipment
     * whose fulfillment order is missing from it is looked up. It replaces the
     * search for changes rather than following it, because the lookup closes
     * everything the search would have; the daily one also retries a row that
     * failed to import, which a search would not offer again until its order
     * changed. A connection idle for more than a day reads in full for the
     * same reason, rather than searching through every order closed meanwhile.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function readFulfillmentOrders(bool $canSearchAllOrders): Collection
    {
        $startedAt = now()->toImmutable();
        $dataSource = $this->dataSource();

        if ($dataSource === null) {
            return $this->fetchFulfillmentOrderShipments();
        }

        $accountKey = 'shopify:'.strtolower((string) ($this->config['shop_domain'] ?? ''));
        $sameAccount = $dataSource->import_account_key === $accountKey;
        $updatedThrough = $sameAccount ? $dataSource->import_updated_through : null;
        $sweptAt = $sameAccount ? $dataSource->import_swept_at : null;

        $remappedAt = $dataSource->locations_remapped_at;

        $this->pendingReadState = [
            'import_account_key' => $accountKey,
            'import_updated_through' => $startedAt,
        ];

        $readsInFull = ! $canSearchAllOrders
            || $updatedThrough === null
            || $sweptAt === null
            || $sweptAt->lessThanOrEqualTo($startedAt->subHours(self::FULL_READ_HOURS))
            || ($remappedAt !== null && $remappedAt->greaterThanOrEqualTo($updatedThrough));

        if ($readsInFull) {
            $this->pendingReadState['import_swept_at'] = $startedAt;

            return $this->fetchFulfillmentOrderShipments();
        }

        return $this->fetchChangedFulfillmentOrderShipments($updatedThrough->toImmutable()->subSeconds(self::READ_OVERLAP_SECONDS));
    }

    private function dataSource(): ?DataSource
    {
        $sourceId = $this->config['_data_source_id'] ?? null;

        return $sourceId ? DataSource::query()->find($sourceId) : null;
    }

    /**
     * Refuse an import the live token cannot complete.
     *
     * Activation checks the same scopes, but a connection activated before a
     * scope joined {@see ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES}
     * — `read_products`, which line-item `variant` needs — would otherwise run
     * on, and the store's declared scopes can also change after activation.
     *
     * @return list<string> the scopes the token holds
     */
    private function assertImportScopes(): array
    {
        $scopes = $this->fetchAccessScopes();
        $missingScopes = array_values(array_diff(ShopifyFulfillmentOrderActivationService::REQUIRED_SCOPES, $scopes));

        if ($missingScopes !== []) {
            throw new DomainException('Reconnect Shopify with the required scopes before importing: '.implode(', ', $missingScopes).'.');
        }

        return $scopes;
    }

    /**
     * Fetch the active location catalog for this Shopify store.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function fetchLocations(): Collection
    {
        /** @var array<int, array<string, mixed>> $locations */
        $locations = [];
        $cursor = null;

        do {
            $response = $this->connector->send(
                new GraphQL(self::LOCATIONS_QUERY, array_filter(['cursor' => $cursor]))
            );
            $json = $response->json();

            if (! empty($json['errors'])) {
                throw new RuntimeException('Shopify GraphQL error: '.json_encode($json['errors']));
            }

            $data = $json['data']['locations'] ?? [];

            foreach ($data['nodes'] ?? [] as $location) {
                if ($location['isActive'] ?? true) {
                    $locations[] = [
                        'external_id' => $location['id'],
                        'external_code' => null,
                        'name' => $location['name'],
                        'address' => $location['address'] ?? null,
                        'is_active' => true,
                    ];
                }
            }

            $pageInfo = $data['pageInfo'] ?? [];
            $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($cursor !== null);

        return collect($locations);
    }

    /** @return list<string> */
    public function fetchAccessScopes(): array
    {
        $response = $this->connector->send(new GraphQL(self::ACCESS_SCOPES_QUERY));
        $json = $response->json();

        if (! empty($json['errors'])) {
            throw new RuntimeException('Shopify GraphQL error: '.json_encode($json['errors']));
        }

        return collect($json['data']['currentAppInstallation']['accessScopes'] ?? [])
            ->pluck('handle')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Text metafields a part number could be read from, for the connection
     * form's picker: every definition on variants and products, plus
     * metafields on recently created variants that have no definition — a
     * channel app may write one without defining it.
     *
     * @return array<string, string> Option value ({@see ShopifyMetafieldReference::optionValue()}) => label
     *
     * @throws DomainException when the token lacks `read_products`
     */
    public function fetchPartNumberMetafieldChoices(): array
    {
        if (! in_array('read_products', $this->fetchAccessScopes(), true)) {
            throw new DomainException('Reconnect Shopify with the read_products scope to list its metafields.');
        }

        $choices = [];

        foreach (ShopifyMetafieldOwner::cases() as $owner) {
            foreach ($this->fetchMetafieldDefinitions($owner) as $definition) {
                $reference = new ShopifyMetafieldReference($owner, $definition['namespace'], $definition['key']);
                $choices[$reference->optionValue()] = filled($definition['name'] ?? null)
                    ? "{$definition['name']} — {$reference->label()}"
                    : $reference->label();
            }
        }

        $json = $this->graphql(self::SAMPLED_VARIANT_METAFIELDS_QUERY);

        foreach ($json['data']['productVariants']['nodes'] ?? [] as $variant) {
            foreach ($variant['metafields']['nodes'] ?? [] as $metafield) {
                if (! in_array($metafield['type'] ?? null, self::TEXT_METAFIELD_TYPES, true)) {
                    continue;
                }

                $reference = new ShopifyMetafieldReference(ShopifyMetafieldOwner::Variant, $metafield['namespace'], $metafield['key']);
                $choices[$reference->optionValue()] ??= "{$reference->label()} — undefined";
            }
        }

        return $choices;
    }

    /**
     * @return list<array{name: ?string, namespace: string, key: string}>
     */
    private function fetchMetafieldDefinitions(ShopifyMetafieldOwner $owner): array
    {
        $definitions = [];
        $cursor = null;

        do {
            $json = $this->graphql(self::METAFIELD_DEFINITIONS_QUERY, array_filter([
                'ownerType' => $owner->ownerType(),
                'cursor' => $cursor,
            ]));
            $data = $json['data']['metafieldDefinitions'] ?? [];

            foreach ($data['nodes'] ?? [] as $definition) {
                if (in_array($definition['type']['name'] ?? null, self::TEXT_METAFIELD_TYPES, true)) {
                    $definitions[] = [
                        'name' => $definition['name'] ?? null,
                        'namespace' => $definition['namespace'],
                        'key' => $definition['key'],
                    ];
                }
            }

            $pageInfo = $data['pageInfo'] ?? [];
            $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($cursor !== null);

        return $definitions;
    }

    /**
     * Send a query, waiting out Shopify's throttle.
     *
     * A throttled query comes back as HTTP 200 with a `THROTTLED` error, which
     * the connector does not retry. Shopify refills its bucket in seconds, so
     * the wait its `throttleStatus` implies is taken here and the read carries
     * on, rather than failing a run that would start over next time and never
     * finish a large first read.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphql(string $query, array $variables = []): array
    {
        for ($attempt = 1; ; $attempt++) {
            $json = $this->connector->send(new GraphQL($query, $variables))->json();

            if (empty($json['errors'])) {
                return $json;
            }

            if ($attempt >= self::THROTTLE_ATTEMPTS || ! self::isThrottled($json)) {
                throw new RuntimeException('Shopify GraphQL error: '.json_encode($json['errors']));
            }

            Sleep::for(self::throttleWaitSeconds($json))->seconds();
        }
    }

    /** @param  array<string, mixed>  $json */
    private static function isThrottled(array $json): bool
    {
        foreach ($json['errors'] ?? [] as $error) {
            if (($error['extensions']['code'] ?? null) === 'THROTTLED') {
                return true;
            }
        }

        return false;
    }

    /**
     * How long until the bucket holds what the query asked for.
     *
     * @param  array<string, mixed>  $json
     */
    private static function throttleWaitSeconds(array $json): int
    {
        $cost = $json['extensions']['cost'] ?? [];
        $requested = (float) ($cost['requestedQueryCost'] ?? 0);
        $available = (float) ($cost['throttleStatus']['currentlyAvailable'] ?? 0);
        $restoreRate = (float) ($cost['throttleStatus']['restoreRate'] ?? 0);

        if ($restoreRate <= 0) {
            return self::MAX_THROTTLE_WAIT_SECONDS;
        }

        return (int) min(self::MAX_THROTTLE_WAIT_SECONDS, max(1, ceil(($requested - $available) / $restoreRate)));
    }

    public function fetchShipmentItems(string $sourceRecordId): Collection
    {
        $order = $this->orderCache[$sourceRecordId] ?? null;

        if (! $order) {
            return collect();
        }

        return collect($order['lineItems'] ?? [])
            ->filter(fn (array $item): bool => ($item['requiresShipping'] ?? false) && ($item['remainingQuantity'] ?? 0) > 0)
            ->map(fn (array $item): array => $this->mapFulfillmentOrderLineItem($item))
            ->values();
    }

    /**
     * Re-point shipments whose fulfillment order Shopify has replaced.
     *
     * Delegated whole to {@see ShopifyFulfillmentOrderRepointer}: what belongs
     * here is only the reading of Shopify's own shape into the identity that
     * survives a replacement — the order, the assigned location, and the goods.
     * All three are already on the mapped row, so recognizing a replacement
     * costs no extra request and does not depend on item import being on.
     *
     * @param  Collection<int, array<string, mixed>>  $shipments
     */
    public function reconcileSupersededRecords(DataSource $dataSource, Collection $shipments): int
    {
        return app(ShopifyFulfillmentOrderRepointer::class)->repoint(
            $dataSource,
            $shipments->map(fn (array $row): ShopifyFulfillmentOrderIdentity => new ShopifyFulfillmentOrderIdentity(
                fulfillmentOrderId: (string) $row['source_record_id'],
                orderId: $row['metadata']['shopify_order_id'] ?? null,
                locationId: $row['metadata']['shopify_location_id'] ?? null,
                goodsFingerprint: $row['metadata'][ShopifyGoodsFingerprint::METADATA_KEY] ?? null,
            )),
            $this->answeredFulfillmentOrderIds,
        );
    }

    /**
     * Close the Shipments whose fulfillment order Shopify has closed since it
     * left the feed. Delegated whole to {@see ShopifyClosedFulfillmentOrderCloser}.
     *
     * Where the connection read through is then recorded, and only if every
     * lookup answered: a search for changes would not return a Shipment's
     * closed fulfillment order again, so a failed one reads the same changes
     * next run. A failed fetch never gets here, and a failed write is retried
     * by the daily full read.
     *
     * @param  Collection<int, array<string, mixed>>  $shipments
     */
    public function closeRecordsClosedAtSource(DataSource $dataSource, Collection $shipments): SourceClosureResult
    {
        $result = app(ShopifyClosedFulfillmentOrderCloser::class)->close(
            $dataSource,
            $this->connector,
            $shipments,
            $this->answeredFulfillmentOrderIds,
        );

        if ($this->pendingReadState !== null && $result->errors === []) {
            DataSource::query()->whereKey($dataSource->id)->update($this->pendingReadState);
        }

        return $result;
    }

    /**
     * Every open fulfillment order.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchFulfillmentOrderShipments(): Collection
    {
        $shipments = [];
        $cursor = null;

        do {
            $json = $this->graphql(
                $this->importQuery(self::FULFILLMENT_ORDERS_QUERY),
                $this->lineItemVariables(array_filter(['cursor' => $cursor])),
            );

            $data = $json['data']['fulfillmentOrders'] ?? [];

            foreach ($data['nodes'] ?? [] as $fulfillmentOrder) {
                if (($row = $this->importedRow($fulfillmentOrder)) !== null) {
                    $shipments[] = $row;
                }
            }

            $pageInfo = $data['pageInfo'] ?? [];
            $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($cursor !== null);

        return collect($shipments);
    }

    /**
     * The open fulfillment orders of every order updated after `$since`,
     * noting every fulfillment order those orders hold.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchChangedFulfillmentOrderShipments(CarbonImmutable $since): Collection
    {
        $statuses = $this->changedFulfillmentOrderStatuses($since);
        $this->answeredFulfillmentOrderIds = array_keys($statuses);

        $openIds = array_keys(array_filter(
            $statuses,
            fn (string $status): bool => in_array($status, self::IMPORTED_STATUSES, true),
        ));

        $shipments = [];

        foreach (array_chunk($openIds, self::NODES_BATCH_SIZE) as $ids) {
            $json = $this->graphql(
                $this->importQuery(self::FULFILLMENT_ORDER_NODES_QUERY),
                $this->lineItemVariables(['ids' => $ids]),
            );

            foreach ($json['data']['nodes'] ?? [] as $fulfillmentOrder) {
                // A deleted one comes back null, and one closed since the
                // search is no longer imported.
                if (is_array($fulfillmentOrder) && ($row = $this->importedRow($fulfillmentOrder)) !== null) {
                    $shipments[] = $row;
                }
            }
        }

        return collect($shipments);
    }

    /**
     * Every fulfillment order of every order updated after `$since`, keyed by
     * ID, with its status.
     *
     * @return array<string, string>
     */
    private function changedFulfillmentOrderStatuses(CarbonImmutable $since): array
    {
        $statuses = [];
        $cursor = null;
        $query = "updated_at:>'".$since->utc()->format('Y-m-d\TH:i:s\Z')."'";

        do {
            $data = $this->graphql(self::CHANGED_ORDERS_QUERY, array_filter(['query' => $query, 'cursor' => $cursor]))['data']['orders'] ?? [];

            foreach ($data['nodes'] ?? [] as $order) {
                $connection = $order['fulfillmentOrders'] ?? [];

                while (true) {
                    foreach ($connection['nodes'] ?? [] as $fulfillmentOrder) {
                        $statuses[(string) $fulfillmentOrder['id']] = (string) ($fulfillmentOrder['status'] ?? '');
                    }

                    $pageInfo = $connection['pageInfo'] ?? [];

                    if (! ($pageInfo['hasNextPage'] ?? false)) {
                        break;
                    }

                    $connection = $this->graphql(self::ORDER_FULFILLMENT_ORDERS_QUERY, [
                        'id' => $order['id'],
                        'cursor' => $pageInfo['endCursor'] ?? null,
                    ])['data']['order']['fulfillmentOrders'] ?? [];
                }
            }

            $pageInfo = $data['pageInfo'] ?? [];
            $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($cursor !== null);

        return $statuses;
    }

    /**
     * The row a fulfillment order imports as, or null when it imports none.
     *
     * @param  array<string, mixed>  $fulfillmentOrder
     * @return array<string, mixed>|null
     */
    private function importedRow(array $fulfillmentOrder): ?array
    {
        if (! in_array($fulfillmentOrder['status'] ?? '', self::IMPORTED_STATUSES, true)) {
            return null;
        }

        $fulfillmentOrder['lineItems'] = $this->allFulfillmentOrderItems($fulfillmentOrder);
        $mapped = $this->mapFulfillmentOrderToShipment($fulfillmentOrder);
        $this->orderCache[$mapped['source_record_id']] = $fulfillmentOrder;

        return $mapped;
    }

    /** A query reading fulfillment orders as the import does. */
    private function importQuery(string $query): string
    {
        return $this->lineItemQuery($query."\n".self::IMPORTED_FULFILLMENT_ORDER_FRAGMENT);
    }

    /** @return array<int, array<string, mixed>> */
    private function allFulfillmentOrderItems(array $fulfillmentOrder): array
    {
        $connection = $fulfillmentOrder['lineItems'] ?? [];
        $items = $connection['nodes'] ?? [];
        $pageInfo = $connection['pageInfo'] ?? [];
        $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;

        while ($cursor !== null) {
            $json = $this->graphql(
                $this->lineItemQuery(self::FULFILLMENT_ORDER_ITEMS_QUERY),
                $this->lineItemVariables([
                    'id' => $fulfillmentOrder['id'],
                    'cursor' => $cursor,
                ]),
            );

            $connection = $json['data']['fulfillmentOrder']['lineItems'] ?? [];
            array_push($items, ...($connection['nodes'] ?? []));
            $pageInfo = $connection['pageInfo'] ?? [];
            $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        }

        return $items;
    }

    /**
     * Resolve the destination's state as a code rather than a full name.
     *
     * `FulfillmentOrderDestination` exposes only `province`, which is the full
     * name ("Armed Forces Europe"), and carriers want the two-letter code. The
     * order's shipping address carries `provinceCode`, so prefer that whenever
     * it describes the same destination — a fulfillment order can in principle
     * be routed elsewhere, and the postal code is the cheapest way to tell.
     * Otherwise fall back to the name, which normalization handles.
     */
    private function destinationProvince(array $fulfillmentOrder): ?string
    {
        $destination = $fulfillmentOrder['destination'] ?? [];
        $shippingAddress = data_get($fulfillmentOrder, 'order.shippingAddress') ?? [];

        $sameDestination = filled($destination['zip'] ?? null)
            && filled($shippingAddress['zip'] ?? null)
            && strcasecmp(trim((string) $destination['zip']), trim((string) $shippingAddress['zip'])) === 0;

        if ($sameDestination && filled($shippingAddress['provinceCode'] ?? null)) {
            return $shippingAddress['provinceCode'];
        }

        return $destination['province'] ?? null;
    }

    /** @return array<string, mixed> */
    private function mapFulfillmentOrderToShipment(array $fulfillmentOrder): array
    {
        $order = $fulfillmentOrder['order'] ?? [];
        $destination = $fulfillmentOrder['destination'] ?? [];
        $assigned = $fulfillmentOrder['assignedLocation'] ?? [];
        $shopifyLocation = $assigned['location'] ?? [];
        $lineItems = collect($fulfillmentOrder['lineItems'] ?? [])
            ->filter(fn (array $item): bool => ($item['requiresShipping'] ?? false) && ($item['remainingQuantity'] ?? 0) > 0);
        $value = $lineItems->sum(fn (array $item): float => (float) data_get($item, 'lineItem.originalUnitPriceSet.shopMoney.amount', 0)
            * (int) ($item['remainingQuantity'] ?? 0));

        return [
            ...$this->customsFields($order),
            'source_record_id' => $fulfillmentOrder['id'],
            'shipment_reference' => $order['name'] ?? $fulfillmentOrder['id'],
            'first_name' => $destination['firstName'] ?? null,
            'last_name' => $destination['lastName'] ?? null,
            'company' => $destination['company'] ?? null,
            'address1' => $destination['address1'] ?? null,
            'address2' => $destination['address2'] ?? null,
            'city' => $destination['city'] ?? null,
            'state_or_province' => $this->destinationProvince($fulfillmentOrder),
            'postal_code' => $destination['zip'] ?? null,
            'country' => $destination['countryCode'] ?? 'US',
            'phone' => $destination['phone'] ?? null,
            'email' => $order['email'] ?? null,
            'value' => round($value, 2),
            'channel_id' => $this->config['channel_name'] ?? 'Shopify',
            'shipping_method_id' => $this->config['shipping_method'] ?? null,
            'deliver_by' => null,
            'source_location' => [
                'external_id' => $shopifyLocation['id'] ?? '',
                'external_code' => null,
                'name' => $shopifyLocation['name'] ?? $assigned['name'] ?? 'Unknown Shopify location',
                'address' => $shopifyLocation['address'] ?? array_filter([
                    'address1' => $assigned['address1'] ?? null,
                    'address2' => $assigned['address2'] ?? null,
                    'city' => $assigned['city'] ?? null,
                    'provinceCode' => $assigned['province'] ?? null,
                    'zip' => $assigned['zip'] ?? null,
                    'countryCode' => $assigned['countryCode'] ?? null,
                ]),
                'is_active' => $shopifyLocation['isActive'] ?? true,
            ],
            'metadata' => [
                'shopify_order_id' => $order['id'] ?? null,
                'shopify_fulfillment_order_id' => $fulfillmentOrder['id'],
                'shopify_location_id' => $shopifyLocation['id'] ?? null,
                // Recorded so a replacement fulfillment order can be told from
                // a sibling later, when this one is closed and unreadable.
                ShopifyGoodsFingerprint::METADATA_KEY => $this->goods->forLineItems(
                    $fulfillmentOrder['lineItems'] ?? [],
                    'remainingQuantity',
                ),
            ],
        ];
    }

    /**
     * The customs terms an order states: DDP when Shopify charged duties at
     * checkout, and the tax ID the buyer gave for Brazil or South Korea.
     *
     * A key is left out when the order says nothing, so a value a manager set
     * on the Shipment survives the next import. Charging no duties is not a
     * decision to ship DDU, so DDP is the only term this sets.
     * `Order.dutiesIncluded` is not the test: it describes duties-included
     * pricing and is false on an order that was charged duties.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, string>
     */
    private function customsFields(array $order): array
    {
        $fields = [];

        if ((float) data_get($order, 'originalTotalDutiesSet.shopMoney.amount', 0) > 0) {
            $fields['duties_terms'] = DutiesTerms::Ddp->value;
        }

        $credentials = collect(data_get($order, 'localizedFields.nodes', []))
            ->filter(fn (mixed $field): bool => is_array($field) && filled($field['value'] ?? null))
            ->mapWithKeys(fn (array $field): array => [(string) ($field['key'] ?? '') => trim((string) $field['value'])]);

        if (filled($pccc = $credentials->get('SHIPPING_CREDENTIAL_KR'))) {
            $fields += ['recipient_tax_id_type' => RecipientTaxIdType::Pccc->value, 'recipient_tax_id' => $pccc];
        } elseif (filled($brazil = $credentials->get('SHIPPING_CREDENTIAL_BR') ?? $credentials->get('TAX_CREDENTIAL_BR'))) {
            // One Shopify field holds a CPF or a CNPJ; its length tells them apart.
            $type = strlen(RecipientTaxIdType::Cnpj->normalize($brazil)) === 14 ? RecipientTaxIdType::Cnpj : RecipientTaxIdType::Cpf;
            $fields += ['recipient_tax_id_type' => $type->value, 'recipient_tax_id' => $brazil];
        }

        return $fields;
    }

    /**
     * The metafield this connection reads a part number from, or null when
     * it reads none.
     */
    private function partNumberMetafield(): ?ShopifyMetafieldReference
    {
        return ShopifyMetafieldReference::fromSetting($this->config['part_number_metafield'] ?? null);
    }

    /**
     * A line-item query with the part-number metafield selected on the owner
     * the connection names, and only there. Namespace and key travel as
     * variables ({@see lineItemVariables()}), never in the query text.
     */
    private function lineItemQuery(string $query): string
    {
        $partNumber = $this->partNumberMetafield();
        $metafield = 'partNumber: metafield(namespace: $pnNamespace, key: $pnKey) { jsonValue }';

        return strtr($query, [
            '{{partNumberVariables}}' => $partNumber ? ', $pnNamespace: String!, $pnKey: String!' : '',
            '{{partNumberSelection}}' => match ($partNumber?->owner) {
                null => '',
                ShopifyMetafieldOwner::Variant => $metafield,
                ShopifyMetafieldOwner::Product => "product { {$metafield} }",
            },
        ]);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function lineItemVariables(array $variables): array
    {
        $partNumber = $this->partNumberMetafield();

        return $partNumber
            ? [...$variables, 'pnNamespace' => $partNumber->namespace, 'pnKey' => $partNumber->key]
            : $variables;
    }

    /** @return array<string, mixed> */
    private function mapFulfillmentOrderLineItem(array $item): array
    {
        $weightLbs = self::poundsFrom($item['weight'] ?? null);

        $variant = $item['variant'] ?? [];
        $barcodes = data_get($variant, 'barcodes.nodes');
        $barcodes = is_array($barcodes) ? $barcodes : [];

        return [
            // The same rule the goods fingerprint uses, so a fingerprint taken
            // from a raw Shopify node names these items by the same strings.
            'sku' => ShopifyGoodsFingerprint::skuFor($item),
            'name' => $item['productTitle'] ?? null,
            'quantity' => (int) ($item['remainingQuantity'] ?? 0),
            'value' => (float) data_get($item, 'lineItem.originalUnitPriceSet.shopMoney.amount', 0),
            'barcode' => self::scanBarcodeFrom($barcodes),
            'gtin' => self::gtinFrom($barcodes),
            'weight' => $weightLbs,
            'manufacturer_part_number' => self::typedPartNumberFrom($barcodes) ?? $this->partNumberFrom($variant),
            'hs_tariff_number' => self::filledText(data_get($variant, 'inventoryItem.harmonizedSystemCode')),
            'country_of_origin' => self::filledText(data_get($variant, 'inventoryItem.countryCodeOfOrigin')),
        ];
    }

    /**
     * The first `NS_PID` barcode, the part number Shopify made a field for,
     * cut to the same 100 characters as the metafield's.
     *
     * @param  list<array<string, mixed>>  $barcodes
     */
    private static function typedPartNumberFrom(array $barcodes): ?string
    {
        foreach ($barcodes as $barcode) {
            if (($barcode['type'] ?? null) !== 'NS_PID') {
                continue;
            }

            $value = self::filledText($barcode['value'] ?? null);

            if ($value !== null) {
                return self::filledText(mb_substr($value, 0, 100));
            }
        }

        return null;
    }

    /**
     * The first barcode Shopify typed as a GTIN family standard and whose
     * value passes the check digit. An untyped value never fills it.
     *
     * @param  list<array<string, mixed>>  $barcodes
     */
    private static function gtinFrom(array $barcodes): ?string
    {
        foreach ($barcodes as $barcode) {
            if (! in_array($barcode['type'] ?? null, ['UPC', 'EAN', 'GTIN', 'ISBN'], true)) {
                continue;
            }

            $value = self::filledText($barcode['value'] ?? null);

            if ($value !== null && Gtin::isValid($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The first barcode that is not an `NS_PID`, which Pack scans. Null when
     * every entry is a part number, which leaves the Product's barcode alone.
     *
     * @param  list<array<string, mixed>>  $barcodes
     */
    private static function scanBarcodeFrom(array $barcodes): ?string
    {
        foreach ($barcodes as $barcode) {
            if (($barcode['type'] ?? null) === 'NS_PID') {
                continue;
            }

            $value = self::filledText($barcode['value'] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The part number from the metafield the connection names: a list's
     * first entry, cut to the column's and UPS's `ProductID` 100 characters.
     */
    private function partNumberFrom(array $variant): ?string
    {
        $value = match ($this->partNumberMetafield()?->owner) {
            null => null,
            ShopifyMetafieldOwner::Variant => data_get($variant, 'partNumber.jsonValue'),
            ShopifyMetafieldOwner::Product => data_get($variant, 'product.partNumber.jsonValue'),
        };

        if (is_array($value)) {
            $value = array_values($value)[0] ?? null;
        }

        $value = self::filledText($value);

        return $value === null ? null : self::filledText(mb_substr($value, 0, 100));
    }

    /**
     * A scalar as trimmed text, or null when it is blank or not a scalar.
     */
    private static function filledText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * A Shopify `Weight` in pounds, or null when there is no weight to read.
     *
     * Shared with {@see ShopifyShippingLabelService}, which reads the same
     * measurement to decide whether Shopify will declare more weight in customs
     * than the box was weighed at. One conversion table
     * because two would eventually disagree, and a disagreement here reads as a
     * catalog defect rather than as a bug.
     *
     * An unrecognized unit passes its value through unconverted: Shopify's
     * `WeightUnit` enum has four members and all four are handled, so a fifth
     * would be a new one whose scale we do not know, and inventing a factor for
     * it would be worse than taking the number at face value.
     *
     * @param  array<string, mixed>|null  $weight
     */
    public static function poundsFrom(?array $weight): ?float
    {
        if (! $weight || ($weight['value'] ?? 0) <= 0) {
            return null;
        }

        return (float) match ($weight['unit'] ?? '') {
            'POUNDS' => $weight['value'],
            'OUNCES' => $weight['value'] / 16,
            'GRAMS' => $weight['value'] / 453.59237,
            'KILOGRAMS' => $weight['value'] * 2.20462,
            default => $weight['value'],
        };
    }

    public function getFieldMapping(): array
    {
        return [];
    }

    public function markExported(string $sourceRecordId): bool
    {
        // No-op: Shopify tracks fulfillment status natively.
        // Orders are excluded from future imports once fulfilled.
        return false;
    }

    public function getDestinationName(): string
    {
        return 'shopify';
    }

    /**
     * Write the tracking number back to Shopify as a fulfillment.
     *
     * Skipped outright for a label bought through Shopify Shipping. Buying the
     * postage *is* the fulfillment: Shopify creates the fulfillment with the
     * tracking information already on it and closes the fulfillment order in
     * the same write, so `fulfillmentCreate` afterwards comes back with
     * `Fulfillment order N has an unfulfillable status= closed` — and the
     * export's job here was done before it ran.
     *
     * That closed-status reply is deliberately *not* swallowed further down.
     * The same message reaches a package whose postage came from one of our own
     * carrier accounts and whose stored fulfillment order has since been closed
     * and replaced (see issue `18`), and that is a real export failure: Shopify
     * has not been told what shipped, and recording it as success would hide
     * that. The label ID is what separates the two, so it is what this gates on
     * — not the message, which is prose Shopify controls. `fulfillmentCreate`
     * returns the base `UserError` type, whose only fields are `field` and
     * `message`, so there is no error code to key on instead.
     *
     * Checked before the credential check, deliberately, and for the reason
     * {@see AmazonSource::exportPackage()} gives: there is nothing to send, so
     * there is nothing credentials are needed for, and a seller who rotates
     * them after the label was bought would otherwise fail an export that had
     * already succeeded at purchase time.
     *
     * Returns the fulfillment's ID, which the Label keeps so that a void can
     * cancel it ({@see cancelFulfillment()}).
     */
    public function exportPackage(array $data): ?string
    {
        if (filled($data['_shopify_shipping_label_id'] ?? null)) {
            Log::info('Skipping Shopify fulfillment for a Shopify Shipping label', [
                'shopify_shipping_label_id' => $data['_shopify_shipping_label_id'],
                'package' => $data['_package_reference_id'] ?? null,
            ]);

            return null;
        }

        $this->validateExportConfiguration();

        $fulfillmentOrderId = $data['fulfillment_order_id'] ?? null;
        $shipmentReference = filled($data['shipment_reference'] ?? null)
            ? (string) $data['shipment_reference']
            : 'package '.($data['_package_reference_id'] ?? 'unknown');

        if (empty($fulfillmentOrderId)) {
            throw new PermanentExportException(
                "Cannot export package for shipment {$shipmentReference}: no fulfillment order ID in metadata."
            );
        }

        $trackingCompany = self::CARRIER_MAP[$data['carrier'] ?? ''] ?? ($data['carrier'] ?? null);

        $variables = [
            'fulfillment' => [
                'lineItemsByFulfillmentOrder' => [
                    ['fulfillmentOrderId' => $fulfillmentOrderId],
                ],
                'notifyCustomer' => (bool) ($this->config['notify_customer'] ?? false),
                'trackingInfo' => [
                    'company' => $trackingCompany,
                    'number' => $data['tracking_number'] ?? null,
                ],
            ],
        ];

        $response = $this->connector->send(
            new GraphQL(self::FULFILLMENT_MUTATION, $variables)
        );

        $json = $response->json();

        if (! empty($json['errors'])) {
            $messages = array_map(
                fn (array $error): string => (string) ($error['message'] ?? 'Unknown GraphQL error'),
                $json['errors'],
            );
            $message = 'Shopify GraphQL error: '.implode('; ', $messages);
            $isRetryable = collect($json['errors'])->contains(
                fn (array $error): bool => in_array(
                    strtoupper((string) ($error['extensions']['code'] ?? '')),
                    self::RETRYABLE_GRAPHQL_ERROR_CODES,
                    true,
                ),
            );

            throw $isRetryable
                ? new RuntimeException($message)
                : new PermanentExportException($message);
        }

        $userErrors = $json['data']['fulfillmentCreate']['userErrors'] ?? [];

        if (! empty($userErrors)) {
            $messages = array_map(
                fn (array $e): string => (is_array($e['field'] ?? null)
                    ? implode('.', $e['field'])
                    : ($e['field'] ?? 'unknown')).': '.($e['message'] ?? ''),
                $userErrors
            );
            $message = 'Shopify fulfillment error: '.implode('; ', $messages);
            $allPermanent = collect($userErrors)->every(fn (array $error): bool => str_contains(
                strtolower((string) ($error['message'] ?? '')),
                'already fulfilled',
            ));

            // An earlier Label's fulfillment is the likeliest thing to have
            // fulfilled the order, and it carries a dead tracking number. Read
            // as success, the export would leave the customer holding it
            // (`project-review/09`).
            if ($allPermanent && ! ($data['_has_voided_label'] ?? false)) {
                return null;
            }

            throw new PermanentExportException($message);
        }

        $fulfillmentId = $json['data']['fulfillmentCreate']['fulfillment']['id'] ?? null;

        return filled($fulfillmentId) ? (string) $fulfillmentId : null;
    }

    /**
     * Cancel a fulfillment an export created, after its Label was voided.
     *
     * Shopify reopens the goods on a fulfillment order of its own choosing,
     * often a new one, so the caller re-points the shipment afterwards.
     *
     * Succeeds only when Shopify hands back this fulfillment as `CANCELLED`.
     * An answer that names no fulfillment is not a cancel. Read as one, the
     * void would report success while the customer keeps the dead number.
     *
     * @throws RuntimeException when Shopify refuses, cannot be asked, or does not confirm the cancel
     */
    public function cancelFulfillment(string $fulfillmentId): void
    {
        $this->validateExportConfiguration();

        $json = $this->connector->send(
            new GraphQL(self::FULFILLMENT_CANCEL_MUTATION, ['id' => $fulfillmentId])
        )->json();

        if (! empty($json['errors'])) {
            throw new RuntimeException('Shopify GraphQL error: '.implode('; ', array_map(
                fn (array $error): string => (string) ($error['message'] ?? 'Unknown GraphQL error'),
                $json['errors'],
            )));
        }

        $userErrors = $json['data']['fulfillmentCancel']['userErrors'] ?? [];

        if (! empty($userErrors)) {
            throw new RuntimeException('Shopify refused to cancel the fulfillment: '.implode('; ', array_map(
                fn (array $error): string => (string) ($error['message'] ?? 'Unknown error'),
                $userErrors,
            )));
        }

        $fulfillment = $json['data']['fulfillmentCancel']['fulfillment'] ?? null;

        if (($fulfillment['id'] ?? null) !== $fulfillmentId || ($fulfillment['status'] ?? null) !== 'CANCELLED') {
            throw new RuntimeException(
                'Shopify did not confirm the fulfillment was cancelled (status: '.($fulfillment['status'] ?? 'none').')'
            );
        }
    }

    public function validateExportConfiguration(): void
    {
        $hasToken = filled($this->config['oauth_access_token'] ?? null);
        $hasCredentials = filled($this->config['client_id'] ?? null)
            && filled($this->config['client_secret'] ?? null);

        if (empty($this->config['shop_domain'] ?? null) || (! $hasToken && ! $hasCredentials)) {
            throw new InvalidArgumentException('Shopify credentials are not configured for this source.');
        }
    }
}
