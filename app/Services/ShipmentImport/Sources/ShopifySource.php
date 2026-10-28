<?php

namespace App\Services\ShipmentImport\Sources;

use App\Contracts\ClosesRecordsClosedAtSource;
use App\Contracts\DataSourceInterface;
use App\Contracts\ExportDestinationInterface;
use App\Contracts\ReadsInPages;
use App\Contracts\ReconcilesSupersededRecords;
use App\Contracts\SyncsProductCatalog;
use App\DataTransferObjects\ShipmentImport\ProductCatalogPage;
use App\DataTransferObjects\ShipmentImport\ShopifyFulfillmentOrderIdentity;
use App\DataTransferObjects\ShipmentImport\ShopifyMetafieldReference;
use App\DataTransferObjects\ShipmentImport\SourceClosureResult;
use App\Enums\DutiesTerms;
use App\Enums\RecipientTaxIdType;
use App\Enums\ShopifyMetafieldOwner;
use App\Exceptions\CatalogReadMustStartOver;
use App\Exceptions\PermanentExportException;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Http\Integrations\Shopify\ShopifyConnector;
use App\Models\Carrier;
use App\Models\DataSource;
use App\Models\DataSourceLocation;
use App\Models\Location;
use App\Services\ShipmentImport\ImportRunRecorder;
use App\Services\ShipmentImport\ShopifyClosedFulfillmentOrderCloser;
use App\Services\ShipmentImport\ShopifyFulfillmentOrderRepointer;
use App\Services\ShopifyGoodsFingerprint;
use App\Services\ShopifyShippingLabelService;
use App\Support\Gtin;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;

class ShopifySource implements ClosesRecordsClosedAtSource, DataSourceInterface, ExportDestinationInterface, ReadsInPages, ReconcilesSupersededRecords, SyncsProductCatalog
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

    /**
     * The items of the rows on the page in hand, keyed by source record ID,
     * for {@see fetchShipmentItems()}. Replaced by each page.
     *
     * @var array<string, Collection<int, array<string, mixed>>>
     */
    private array $pageItems = [];

    /**
     * The fulfillment orders a search for changes found to import, which
     * {@see fetchShipmentPages()} reads by ID. Null after a full read, whose
     * pages read every open one again.
     *
     * @var list<string>|null
     */
    private ?array $changedFulfillmentOrderIds = null;

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

    /**
     * Scopes a fulfillment-order import needs. Checked on every location sync
     * and when an import starts.
     *
     * `read_products` is for line-item `variant`, which carries the barcode;
     * Shopify gates `ProductVariant` behind it.
     */
    public const REQUIRED_SCOPES = [
        'read_orders',
        'read_products',
        'read_locations',
        'read_merchant_managed_fulfillment_orders',
        'write_merchant_managed_fulfillment_orders',
    ];

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
          id status fulfillBy
          deliveryMethod { presentedName maxDeliveryDateTime }
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
     * What identifies a fulfillment order as work ({@see ShopifyFulfillmentOrderIdentity}):
     * its order, its location, and its goods. None of the address, barcodes,
     * part number or customs fields a row reads.
     */
    private const FULFILLMENT_ORDER_IDENTITY_FRAGMENT = <<<'GRAPHQL'
        fragment FulfillmentOrderIdentity on FulfillmentOrder {
          id status
          order { id }
          assignedLocation { location { id } }
          lineItems(first: 40) {
            pageInfo { hasNextPage endCursor }
            nodes {
              sku remainingQuantity requiresShipping
              variant { id }
            }
          }
        }
        GRAPHQL;

    /**
     * Every open fulfillment order's identity, read before any row
     * (`import-memory/01`). Every field it selects is one
     * {@see FULFILLMENT_ORDERS_QUERY} selects too, at the same page sizes, so
     * a page requests no more points than one of those. Each page's log line
     * records what it requested.
     */
    private const FULFILLMENT_ORDER_IDENTITIES_QUERY = <<<'GRAPHQL'
        query FulfillmentOrderIdentities($cursor: String) {
          fulfillmentOrders(first: 250, after: $cursor, includeClosed: false) {
            pageInfo { hasNextPage endCursor }
            nodes { ...FulfillmentOrderIdentity }
          }
        }
        GRAPHQL;

    /** Fulfillment orders' identities by ID. */
    private const FULFILLMENT_ORDER_IDENTITY_NODES_QUERY = <<<'GRAPHQL'
        query ChangedFulfillmentOrderIdentities($ids: [ID!]!) {
          nodes(ids: $ids) {
            ...FulfillmentOrderIdentity
          }
        }
        GRAPHQL;

    /** The rest of a fulfillment order's line items, as its identity reads them. */
    private const FULFILLMENT_ORDER_IDENTITY_ITEMS_QUERY = <<<'GRAPHQL'
        query FulfillmentOrderIdentityItems($id: ID!, $cursor: String) {
          fulfillmentOrder(id: $id) {
            lineItems(first: 250, after: $cursor) {
              pageInfo { hasNextPage endCursor }
              nodes {
                sku remainingQuantity requiresShipping
                variant { id }
              }
            }
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

    /**
     * The variants of active products, for a product catalog sync
     * (`product-catalog-sync/02`), with the variant fields an order import
     * reads ({@see variantFields()}). 250 to a page requests 90 points, and
     * 101 with a part-number metafield on either owner, on the development
     * store (2026-10-28); a page of 100 variants cost 30 and 34. Far under the
     * 1,000-point limit, so the page size is Shopify's most.
     *
     * `product_status:active` leaves out draft and archived products; the
     * product's `status` is read too, and anything else is skipped.
     */
    private const PRODUCT_VARIANTS_QUERY = <<<'GRAPHQL'
        query CatalogVariants($cursor: String{{partNumberVariables}}) {
          productVariants(first: 250, after: $cursor, query: "product_status:active") {
            pageInfo { hasNextPage endCursor }
            nodes {
              id sku title
              product { title status }
              barcodes(first: 5) { nodes { type value } }
              inventoryItem {
                harmonizedSystemCode countryCodeOfOrigin
                measurement { weight { unit value } }
              }
              {{partNumberSelection}}
            }
          }
        }
        GRAPHQL;

    /** A variant title Shopify gives a product with no options. */
    private const DEFAULT_VARIANT_TITLE = 'Default Title';

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
        $this->validateStoreAccess();

        if (empty($this->config['channel_name'])) {
            throw new InvalidArgumentException('Shopify channel name is not configured.');
        }
    }

    /**
     * What any read of the store needs: where it is, and credentials for it.
     * A catalog sync needs no more, so a connection that imports no orders,
     * and has no channel, can still sync its catalog.
     */
    private function validateStoreAccess(): void
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
    }

    /**
     * Every row at once, for a caller that wants the whole set, such as a dry
     * run. The import reads in pages instead ({@see ReadsInPages}).
     */
    public function fetchShipments(): Collection
    {
        $this->fetchIdentities();

        $rows = [];
        $items = [];

        foreach ($this->fetchShipmentPages() as $page) {
            array_push($rows, ...$page->all());
            $items += $this->pageItems;
        }

        $this->pageItems = $items;

        return collect($rows);
    }

    /**
     * The identity of every fulfillment order this run imports, for
     * re-pointing and closure. Decides what the run reads
     * ({@see readsChangesSince()}), and so what {@see fetchShipmentPages()}
     * reads after it.
     *
     * @return Collection<int, ShopifyFulfillmentOrderIdentity>
     */
    public function fetchIdentities(): Collection
    {
        $this->pageItems = [];
        $this->changedFulfillmentOrderIds = null;
        $this->answeredFulfillmentOrderIds = null;
        $this->pendingReadState = null;

        $scopes = $this->assertImportScopes();
        $since = $this->readsChangesSince(in_array(self::ALL_ORDERS_SCOPE, $scopes, true));

        if ($since === null) {
            return $this->openFulfillmentOrderIdentities();
        }

        $identities = $this->changedFulfillmentOrderIdentities($since);
        $this->changedFulfillmentOrderIds = $identities
            ->map(fn (ShopifyFulfillmentOrderIdentity $identity): string => $identity->fulfillmentOrderId)
            ->all();

        return $identities;
    }

    /**
     * The rows of the fulfillment orders {@see fetchIdentities()} identified,
     * a page at a time: every open one after a full read, read again, or the
     * changed ones it found open, by ID.
     *
     * A fulfillment order created between the two reads is imported without
     * an identity, so it is never re-pointed onto; one closed between them is
     * left for the next run to close.
     *
     * @return Generator<int, Collection<int, array<string, mixed>>>
     */
    public function fetchShipmentPages(): Generator
    {
        if ($this->changedFulfillmentOrderIds === null) {
            yield from $this->openFulfillmentOrderPages();

            return;
        }

        foreach (array_chunk($this->changedFulfillmentOrderIds, self::NODES_BATCH_SIZE) as $ids) {
            $json = $this->graphql(
                $this->importQuery(self::FULFILLMENT_ORDER_NODES_QUERY),
                $this->lineItemVariables(['ids' => $ids]),
            );

            // A deleted one comes back null, and one closed since the search
            // is no longer imported.
            $rows = $this->importedRows(array_filter($json['data']['nodes'] ?? [], 'is_array'));
            unset($json);

            yield $rows;
        }
    }

    /**
     * Whether a run reads only what changed, and from when
     * (`order-reconciliation/11`). Null when it reads every open fulfillment
     * order.
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
     */
    private function readsChangesSince(bool $canSearchAllOrders): ?CarbonImmutable
    {
        $startedAt = now()->toImmutable();
        $dataSource = $this->dataSource();

        if ($dataSource === null) {
            return null;
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

            return null;
        }

        return $updatedThrough->toImmutable()->subSeconds(self::READ_OVERLAP_SECONDS);
    }

    private function dataSource(): ?DataSource
    {
        $sourceId = $this->config['_data_source_id'] ?? null;

        return $sourceId ? DataSource::query()->find($sourceId) : null;
    }

    /**
     * Refuse an import the live token cannot complete.
     *
     * Every run asks, since the store's declared scopes can change at any
     * time. Without `read_merchant_managed_fulfillment_orders` Shopify returns
     * an empty list rather than an error, so an unchecked run would import
     * nothing and report success.
     *
     * @return list<string> the scopes the token holds
     */
    private function assertImportScopes(): array
    {
        $scopes = $this->fetchAccessScopes();
        $missingScopes = array_values(array_diff(self::REQUIRED_SCOPES, $scopes));

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

            $waitSeconds = self::throttleWaitSeconds($json);
            $cost = $json['extensions']['cost'] ?? [];

            Log::info('Shopify throttled a read; waiting for the bucket to refill', [
                'shop_domain' => $this->config['shop_domain'] ?? null,
                'attempt' => $attempt,
                'wait_seconds' => $waitSeconds,
                'requested_cost' => $cost['requestedQueryCost'] ?? null,
                'currently_available' => $cost['throttleStatus']['currentlyAvailable'] ?? null,
                'maximum_available' => $cost['throttleStatus']['maximumAvailable'] ?? null,
                'restore_rate' => $cost['throttleStatus']['restoreRate'] ?? null,
            ]);

            Sleep::for($waitSeconds)->seconds();
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

    /**
     * The items of a row on the page in hand.
     */
    public function fetchShipmentItems(string $sourceRecordId): Collection
    {
        return $this->pageItems[$sourceRecordId] ?? collect();
    }

    /**
     * Re-point shipments whose fulfillment order Shopify has replaced.
     *
     * Delegated whole to {@see ShopifyFulfillmentOrderRepointer}, with every
     * identity {@see fetchIdentities()} read: the identity that survives a
     * replacement is the order, the assigned location, and the goods. Read
     * whether or not item import is on.
     *
     * @param  Collection<int, ShopifyFulfillmentOrderIdentity>  $identities
     */
    public function reconcileSupersededRecords(DataSource $dataSource, Collection $identities): int
    {
        return app(ShopifyFulfillmentOrderRepointer::class)->repoint(
            $dataSource,
            $identities,
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
     * next run. A read that failed, even partway, never gets here, and a
     * failed write is retried by the daily full read.
     *
     * @param  Collection<int, ShopifyFulfillmentOrderIdentity>  $identities  every identity {@see fetchIdentities()} read
     */
    public function closeRecordsClosedAtSource(DataSource $dataSource, Collection $identities): SourceClosureResult
    {
        $result = app(ShopifyClosedFulfillmentOrderCloser::class)->close(
            $dataSource,
            $this->connector,
            $identities,
            $this->answeredFulfillmentOrderIds,
        );

        if ($this->pendingReadState !== null && $result->errors === []) {
            DataSource::query()->whereKey($dataSource->id)->update($this->pendingReadState);
        }

        return $result;
    }

    /**
     * Every open fulfillment order's identity.
     *
     * @return Collection<int, ShopifyFulfillmentOrderIdentity>
     */
    private function openFulfillmentOrderIdentities(): Collection
    {
        $identities = [];
        $cursor = null;

        do {
            $json = $this->graphql($this->identityQuery(self::FULFILLMENT_ORDER_IDENTITIES_QUERY), array_filter(['cursor' => $cursor]));
            $data = $json['data']['fulfillmentOrders'] ?? [];

            foreach ($data['nodes'] ?? [] as $fulfillmentOrder) {
                if (($identity = $this->importedIdentity($fulfillmentOrder)) !== null) {
                    $identities[] = $identity;
                }
            }

            Log::info('Read a page of Shopify fulfillment order identities', [
                'identities_held' => count($identities),
                ...self::queryCost($json),
                ...ImportRunRecorder::memoryContext(),
            ]);

            $pageInfo = $data['pageInfo'] ?? [];
            $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($cursor !== null);

        return collect($identities);
    }

    /**
     * Every open fulfillment order's row, a page at a time.
     *
     * @return Generator<int, Collection<int, array<string, mixed>>>
     */
    private function openFulfillmentOrderPages(): Generator
    {
        $cursor = null;
        $page = 0;

        do {
            $json = $this->graphql(
                $this->importQuery(self::FULFILLMENT_ORDERS_QUERY),
                $this->lineItemVariables(array_filter(['cursor' => $cursor])),
            );

            $data = $json['data']['fulfillmentOrders'] ?? [];
            $rows = $this->importedRows($data['nodes'] ?? []);
            $pageInfo = $data['pageInfo'] ?? [];
            $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;

            // The last of these before a worker dies of its memory limit
            // says how far the read got.
            Log::info('Read a page of Shopify fulfillment orders', [
                'page' => ++$page,
                'rows' => $rows->count(),
                ...self::queryCost($json),
                ...ImportRunRecorder::memoryContext(),
            ]);

            unset($json, $data);

            yield $rows;
        } while ($cursor !== null);
    }

    /**
     * The identities of the open fulfillment orders of every order updated
     * after `$since`, noting every fulfillment order those orders hold.
     *
     * @return Collection<int, ShopifyFulfillmentOrderIdentity>
     */
    private function changedFulfillmentOrderIdentities(CarbonImmutable $since): Collection
    {
        $statuses = $this->changedFulfillmentOrderStatuses($since);
        $this->answeredFulfillmentOrderIds = array_keys($statuses);

        $openIds = array_keys(array_filter(
            $statuses,
            fn (string $status): bool => in_array($status, self::IMPORTED_STATUSES, true),
        ));

        $identities = [];

        foreach (array_chunk($openIds, self::NODES_BATCH_SIZE) as $ids) {
            $json = $this->graphql($this->identityQuery(self::FULFILLMENT_ORDER_IDENTITY_NODES_QUERY), ['ids' => $ids]);

            foreach ($json['data']['nodes'] ?? [] as $fulfillmentOrder) {
                // A deleted one comes back null, and one closed since the
                // search is no longer imported.
                if (is_array($fulfillmentOrder) && ($identity = $this->importedIdentity($fulfillmentOrder)) !== null) {
                    $identities[] = $identity;
                }
            }
        }

        return collect($identities);
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
     * The rows a page of fulfillment orders imports as. Their items replace
     * the last page's, for {@see fetchShipmentItems()}.
     *
     * @param  iterable<int, array<string, mixed>>  $fulfillmentOrders
     * @return Collection<int, array<string, mixed>>
     */
    private function importedRows(iterable $fulfillmentOrders): Collection
    {
        $this->pageItems = [];
        $rows = [];

        foreach ($fulfillmentOrders as $fulfillmentOrder) {
            if (($row = $this->importedRow($fulfillmentOrder)) !== null) {
                $rows[] = $row;
            }
        }

        return collect($rows);
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

        $fulfillmentOrder['lineItems'] = $this->allLineItems(
            $fulfillmentOrder['lineItems'] ?? [],
            fn (string $cursor): array => $this->graphql(
                $this->lineItemQuery(self::FULFILLMENT_ORDER_ITEMS_QUERY),
                $this->lineItemVariables(['id' => $fulfillmentOrder['id'], 'cursor' => $cursor]),
            ),
        );

        $mapped = $this->mapFulfillmentOrderToShipment($fulfillmentOrder);

        $this->pageItems[$mapped['source_record_id']] = collect($fulfillmentOrder['lineItems'])
            ->filter(fn (array $item): bool => ($item['requiresShipping'] ?? false) && ($item['remainingQuantity'] ?? 0) > 0)
            ->map(fn (array $item): array => $this->mapFulfillmentOrderLineItem($item))
            ->values();

        return $mapped;
    }

    /**
     * The identity of a fulfillment order the import imports, or null when it
     * imports none: the same statuses {@see importedRow()} imports.
     *
     * @param  array<string, mixed>  $fulfillmentOrder
     */
    private function importedIdentity(array $fulfillmentOrder): ?ShopifyFulfillmentOrderIdentity
    {
        if (! in_array($fulfillmentOrder['status'] ?? '', self::IMPORTED_STATUSES, true)) {
            return null;
        }

        $fulfillmentOrder['lineItems'] = $this->allLineItems(
            $fulfillmentOrder['lineItems'] ?? [],
            fn (string $cursor): array => $this->graphql(
                self::FULFILLMENT_ORDER_IDENTITY_ITEMS_QUERY,
                ['id' => $fulfillmentOrder['id'], 'cursor' => $cursor],
            ),
        );

        return $this->identityFor($fulfillmentOrder);
    }

    /**
     * What identifies a fulfillment order whose line items are all in hand.
     * Read the same way for its identity and its row, so the two agree.
     *
     * @param  array<string, mixed>  $fulfillmentOrder
     */
    private function identityFor(array $fulfillmentOrder): ShopifyFulfillmentOrderIdentity
    {
        $orderId = data_get($fulfillmentOrder, 'order.id');
        $locationId = data_get($fulfillmentOrder, 'assignedLocation.location.id');

        return new ShopifyFulfillmentOrderIdentity(
            fulfillmentOrderId: (string) $fulfillmentOrder['id'],
            orderId: $orderId === null ? null : (string) $orderId,
            locationId: $locationId === null ? null : (string) $locationId,
            goodsFingerprint: $this->goods->forLineItems($fulfillmentOrder['lineItems'] ?? [], 'remainingQuantity'),
        );
    }

    /** A query reading fulfillment orders as the import does. */
    private function importQuery(string $query): string
    {
        return $this->lineItemQuery($query."\n".self::IMPORTED_FULFILLMENT_ORDER_FRAGMENT);
    }

    /** A query reading fulfillment orders' identities. */
    private function identityQuery(string $query): string
    {
        return $query."\n".self::FULFILLMENT_ORDER_IDENTITY_FRAGMENT;
    }

    /**
     * Every line item of a fulfillment order, reading the pages after the
     * first with `$nextPage`.
     *
     * @param  array<string, mixed>  $connection  the fulfillment order's `lineItems`
     * @param  Closure(string): array<string, mixed>  $nextPage  the response for the line items after a cursor
     * @return array<int, array<string, mixed>>
     */
    private function allLineItems(array $connection, Closure $nextPage): array
    {
        $items = $connection['nodes'] ?? [];
        $pageInfo = $connection['pageInfo'] ?? [];
        $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;

        while ($cursor !== null) {
            $connection = $nextPage($cursor)['data']['fulfillmentOrder']['lineItems'] ?? [];
            array_push($items, ...($connection['nodes'] ?? []));
            $pageInfo = $connection['pageInfo'] ?? [];
            $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        }

        return $items;
    }

    /**
     * What a read cost against Shopify's limit of 1,000 points a query.
     *
     * @param  array<string, mixed>  $json
     * @return array{requested_cost: mixed, actual_cost: mixed}
     */
    private static function queryCost(array $json): array
    {
        return [
            'requested_cost' => $json['extensions']['cost']['requestedQueryCost'] ?? null,
            'actual_cost' => $json['extensions']['cost']['actualQueryCost'] ?? null,
        ];
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
        $identity = $this->identityFor($fulfillmentOrder);
        // The shipping option the buyer chose at checkout, as it was shown to them ("Standard").
        $presentedShippingMethod = data_get($fulfillmentOrder, 'deliveryMethod.presentedName');
        $defaultShippingMethod = $this->config['shipping_method'] ?? null;

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
            'shipping_method_id' => filled($presentedShippingMethod) ? $presentedShippingMethod : $defaultShippingMethod,
            '_shipping_method_fallback' => $defaultShippingMethod,
            // Copied from the rate the buyer chose: set by carrier-calculated or app rates, null for merchant-defined ones.
            'deliver_by' => Location::localDateFromTimestamp(data_get($fulfillmentOrder, 'deliveryMethod.maxDeliveryDateTime')),
            // Unset unless a merchant or an app set it on the fulfillment order.
            'ship_by' => Location::localDateFromTimestamp($fulfillmentOrder['fulfillBy'] ?? null),
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
                'shopify_order_id' => $identity->orderId,
                'shopify_fulfillment_order_id' => $fulfillmentOrder['id'],
                'shopify_location_id' => $identity->locationId,
                // Recorded so a replacement fulfillment order can be told from
                // a sibling later, when this one is closed and unreadable.
                ShopifyGoodsFingerprint::METADATA_KEY => $identity->goodsFingerprint,
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
        $fields = $this->variantFields(is_array($variant) ? $variant : []);

        return [
            // The same rule the goods fingerprint uses, so a fingerprint taken
            // from a raw Shopify node names these items by the same strings.
            'sku' => ShopifyGoodsFingerprint::skuFor($item),
            'name' => $item['productTitle'] ?? null,
            'quantity' => (int) ($item['remainingQuantity'] ?? 0),
            'value' => (float) data_get($item, 'lineItem.originalUnitPriceSet.shopMoney.amount', 0),
            'barcode' => $fields['barcode'],
            'gtin' => $fields['gtin'],
            'weight' => $weightLbs,
            'manufacturer_part_number' => $fields['manufacturer_part_number'],
            'hs_tariff_number' => $fields['hs_tariff_number'],
            'country_of_origin' => $fields['country_of_origin'],
        ];
    }

    /**
     * The Product fields read from a variant itself, the same whether an
     * order import or a catalog sync read it. Name and weight are not among
     * them: an order import takes them from its line item.
     *
     * @param  array<string, mixed>  $variant
     * @return array{barcode: ?string, gtin: ?string, manufacturer_part_number: ?string, hs_tariff_number: ?string, country_of_origin: ?string}
     */
    private function variantFields(array $variant): array
    {
        $barcodes = data_get($variant, 'barcodes.nodes');
        $barcodes = is_array($barcodes) ? $barcodes : [];

        return [
            'barcode' => self::scanBarcodeFrom($barcodes),
            'gtin' => self::gtinFrom($barcodes),
            'manufacturer_part_number' => self::typedPartNumberFrom($barcodes) ?? $this->partNumberFrom($variant),
            'hs_tariff_number' => self::filledText(data_get($variant, 'inventoryItem.harmonizedSystemCode')),
            'country_of_origin' => self::filledText(data_get($variant, 'inventoryItem.countryCodeOfOrigin')),
        ];
    }

    /**
     * Every variant of the store's active products, a page at a time, from
     * the start or from a cursor an earlier page gave
     * (`product-catalog-sync/02`).
     *
     * @return Generator<int, ProductCatalogPage>
     *
     * @throws CatalogReadMustStartOver when the token lacks `read_products`, or Shopify no longer accepts `$cursor`
     */
    public function fetchProductCatalogPages(?string $cursor = null): Generator
    {
        $this->validateStoreAccess();

        if ($cursor === null && ! in_array('read_products', $this->fetchAccessScopes(), true)) {
            throw new CatalogReadMustStartOver('Reconnect Shopify with the read_products scope to sync its product catalog.');
        }

        $query = $this->lineItemQuery(self::PRODUCT_VARIANTS_QUERY);
        $resumed = $cursor !== null;
        $page = 0;

        do {
            $json = $this->catalogPage($query, $cursor);
            $connection = $json['data']['productVariants'] ?? [];
            $page++;

            Log::info('Read a page of Shopify product variants', [
                'shop_domain' => $this->config['shop_domain'] ?? null,
                // Of this read, which may carry on from a saved cursor.
                'page' => $page,
                'from_saved_cursor' => $resumed,
                'variants' => count($connection['nodes'] ?? []),
                ...self::queryCost($json),
            ]);

            /** @var Collection<int, array<string, mixed>> $items */
            $items = collect($connection['nodes'] ?? [])
                ->filter(fn (mixed $variant): bool => is_array($variant) && ($variant['product']['status'] ?? null) === 'ACTIVE')
                ->map(fn (array $variant): array => $this->mapCatalogVariant($variant))
                ->filter(fn (array $item): bool => $item['sku'] !== null)
                ->values();

            $pageInfo = $connection['pageInfo'] ?? [];
            $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;

            yield new ProductCatalogPage($items, $cursor);
        } while ($cursor !== null);
    }

    /**
     * What Shopify answers for a cursor it cannot use (development store,
     * 2026-10-28). Matched exactly: another error that mentions a cursor is
     * not one of these.
     */
    private const REFUSED_CURSOR_MESSAGES = [
        'Malformed cursor',
        'Invalid cursor for current pagination sort.',
    ];

    /**
     * One page of catalog variants. A cursor Shopify refuses stops the sync
     * with a reason rather than its raw error, and the next sync starts from
     * the first page rather than resuming from it.
     *
     * @return array<string, mixed>
     */
    private function catalogPage(string $query, ?string $cursor): array
    {
        try {
            return $this->graphql($query, $this->lineItemVariables(array_filter(['cursor' => $cursor])));
        } catch (RuntimeException $e) {
            $refused = collect(self::REFUSED_CURSOR_MESSAGES)
                ->contains(fn (string $message): bool => str_contains($e->getMessage(), json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

            if ($cursor !== null && $refused) {
                throw new CatalogReadMustStartOver('Shopify no longer accepts the place this sync had reached in the catalog. Products saved so far are kept; the next sync starts from the first page.', previous: $e);
            }

            throw $e;
        }
    }

    /**
     * A catalog variant as the item array an import writes a Product from.
     * The SKU follows the order import's rule, so a SKU-less variant lands on
     * the Product a later order names.
     *
     * @param  array<string, mixed>  $variant
     * @return array<string, mixed>
     */
    private function mapCatalogVariant(array $variant): array
    {
        $productTitle = self::filledText(data_get($variant, 'product.title'));
        $variantTitle = self::filledText($variant['title'] ?? null);
        $weight = data_get($variant, 'inventoryItem.measurement.weight');

        return [
            'sku' => ShopifyGoodsFingerprint::skuFor(['sku' => $variant['sku'] ?? null, 'variant' => ['id' => $variant['id'] ?? null]]),
            'name' => self::productName($variantTitle === null || $variantTitle === self::DEFAULT_VARIANT_TITLE
                ? $productTitle
                : ($productTitle === null ? $variantTitle : "{$productTitle} - {$variantTitle}")),
            'weight' => self::catalogPounds(is_array($weight) ? $weight : null),
            ...$this->variantFields($variant),
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
     * A catalog weight in pounds, or null when it is no value: Shopify reports
     * 0 for a variant with no weight entered, and a weight that rounds to 0.00
     * in the two-decimal column would store as 0 all the same. Null leaves
     * PolyBag's weight alone.
     *
     * @param  array<string, mixed>|null  $weight
     */
    private static function catalogPounds(?array $weight): ?float
    {
        $pounds = self::poundsFrom($weight);

        return $pounds !== null && round($pounds, 2) > 0 ? $pounds : null;
    }

    /**
     * A name cut to the `products.name` column's 255 characters. Shopify allows
     * a product title of 255 alone, so the variant title can take it over,
     * and MySQL's strict mode would fail the whole page on it.
     */
    private static function productName(?string $name): ?string
    {
        return $name === null ? null : rtrim(mb_substr($name, 0, 255));
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
