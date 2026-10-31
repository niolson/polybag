<?php

namespace App\Services\ShipmentImport\Sources;

use App\Contracts\ClosesRecordsClosedAtSource;
use App\Contracts\DataSourceInterface;
use App\Contracts\ExportDestinationInterface;
use App\Contracts\FollowsUpProductCatalogSync;
use App\Contracts\SyncsProductCatalog;
use App\DataTransferObjects\ShipmentImport\ProductCatalogPage;
use App\DataTransferObjects\ShipmentImport\ProductCatalogSyncRun;
use App\DataTransferObjects\ShipmentImport\SourceClosureResult;
use App\Enums\AmazonMarketplace;
use App\Exceptions\CatalogReadMustStartOver;
use App\Exceptions\PermanentExportException;
use App\Http\Integrations\Amazon\AmazonRequestPacer;
use App\Http\Integrations\Amazon\AmazonSpApiConnector;
use App\Http\Integrations\Amazon\AmazonSpApiEnvironment;
use App\Http\Integrations\Amazon\Requests\ConfirmShipment;
use App\Http\Integrations\Amazon\Requests\SearchOrders;
use App\Jobs\LookUpAmazonCatalogIdentifiersJob;
use App\Models\AmazonCatalogLookup;
use App\Models\Carrier;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Product;
use App\Models\Shipment;
use App\Services\ClientContext;
use App\Services\ProductCatalog\AmazonCatalogLookups;
use App\Services\ProductCatalog\AmazonListingCatalog;
use App\Services\SettingsService;
use App\Services\ShipmentImport\AmazonBuyerDataFilter;
use App\Services\ShipmentImport\AmazonCatalogIdentifiers;
use App\Services\ShipmentImport\AmazonClosedOrderCloser;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;

class AmazonSource implements ClosesRecordsClosedAtSource, DataSourceInterface, ExportDestinationInterface, FollowsUpProductCatalogSync, SyncsProductCatalog
{
    /**
     * `fulfillment.fulfilledBy` when Amazon ships the order from its own
     * warehouse (FBA) rather than the seller shipping it (MFN).
     */
    public const FULFILLED_BY_AMAZON = 'AMAZON';

    private array $config;

    private AmazonSpApiConnector $connector;

    /** @var array<string, array> Cached order data keyed by source record ID */
    private array $orderCache = [];

    /**
     * What the catalog answered, keyed by ASIN. An ASIN is present only when its
     * lookup succeeded, whether or not the catalog held anything for it.
     *
     * @var array<string, array{barcode: string|null, part_number: string|null}>
     */
    private array $catalogIdentifiers = [];

    /** When this import's catalog lookup ran, stamped on the Products it answered for. */
    private ?string $catalogCheckedAt = null;

    /** Paces this run's requests; a new run starts with none sent. */
    private AmazonRequestPacer $requests;

    /**
     * The shipped and cancelled orders this run read, keyed by order ID, for
     * {@see closeRecordsClosedAtSource()}.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $closedOrders = [];

    /**
     * What {@see closeRecordsClosedAtSource()} records on the connection once
     * the run completes: the reads still in progress, and how far finished
     * ones read. Null when this run made no scheduled search.
     *
     * @var array<string, mixed>|null
     */
    private ?array $pendingReadState = null;

    private const SEARCH_ORDERS_PAGE_SIZE = 100;

    /** How far back of where the last run read through the next one starts, for orders Amazon indexes late. */
    private const READ_OVERLAP_MINUTES = 10;

    /** How often a scheduled import reads every open order again, not only those that changed. */
    public const SWEEP_HOURS = 24;

    /**
     * How long a stored pagination token is used. Amazon's last 24 hours; a
     * read not finished by then starts over.
     */
    public const TOKEN_LIFETIME_HOURS = 23;

    /** Statuses whose orders are not imported but close their Shipments. */
    private const CLOSED_STATUSES = ['SHIPPED', 'CANCELLED'];

    private const CARRIER_MAP = [
        'USPS' => Carrier::USPS,
        'FEDEX' => Carrier::FEDEX,
        'FedEx' => Carrier::FEDEX,
        'UPS' => Carrier::UPS,
        'DHL' => 'DHL',
    ];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connector = AmazonSpApiConnector::fromSettings($config);
        $this->requests = new AmazonRequestPacer($this->connector);
    }

    public function validateConfiguration(): void
    {
        if (! app(SettingsService::class)->get('require_mfa')) {
            throw new RuntimeException('Multi-factor authentication must be enabled to use Amazon SP-API imports. Enable it in App Settings → Security.');
        }

        $usesBrokerOAuth = ($this->config['auth_mode'] ?? null) === 'authorization_code';

        // Manual connections keep their LWA credentials on the data source.
        $hasOwnCredentials = filled($this->config['client_id'] ?? null)
            && filled($this->config['client_secret'] ?? null);

        if (! $usesBrokerOAuth && ! $hasOwnCredentials) {
            throw new InvalidArgumentException('Amazon SP-API client credentials are not configured for this source.');
        }

        if (empty($this->config['refresh_token'] ?? null)) {
            throw new InvalidArgumentException('Amazon SP-API refresh token is not configured for this source.');
        }

        if (DataSource::amazonMarketplaceIdsIn($this->config) === []) {
            throw new InvalidArgumentException('Amazon SP-API marketplace ID is not configured for this source.');
        }

        if (empty($this->config['channel_name'])) {
            throw new InvalidArgumentException('Amazon channel name is not configured.');
        }
    }

    public function fetchShipments(): Collection
    {
        $this->orderCache = [];
        $this->catalogIdentifiers = [];
        $this->catalogCheckedAt = null;
        $this->requests = new AmazonRequestPacer($this->connector);
        $this->closedOrders = [];
        $this->pendingReadState = null;
        $sandbox = $this->readsFromSandbox();

        if ($sandbox) {
            // v2026-01-01 sandbox test cases only exist for JP/UK/BR marketplaces.
            // Test Case 1: Japan marketplace with all includedData values.
            //
            // A1VC38T7YXB528 is Amazon's JP marketplace ID, which only resolves
            // against the FE sandbox host — hence SearchOrders declaring the FE
            // region. Sending this marketplace ID to the NA sandbox host 403s with
            // "The marketplaces you provided are not valid for region"
            // (see https://github.com/amzn/selling-partner-api-models/issues/5126).
            $rawOrders = $this->searchOrders([
                'marketplaceIds' => 'A1VC38T7YXB528',
                'createdAfter' => '2024-12-25T00:00:00Z',
                'includedData' => 'BUYER,RECIPIENT,PROCEEDS,EXPENSE,PROMOTION,CANCELLATION,FULFILLMENT,PACKAGES',
            ], sandbox: true);
        } else {
            $rawOrders = $this->searchChangedOrders(implode(',', DataSource::amazonMarketplaceIdsIn($this->config)));
        }

        // Barcodes and part numbers come from the catalog, not the order, and
        // only for products missing one — a packer scans the barcode, and an EU
        // consumer label is refused without a part number. Every import does
        // this: the first live Buy Shipping order
        // arrived through the scheduled import with no barcode and had to be
        // packed by hand. Products already complete, or checked recently, are
        // skipped, so a scheduled run over open orders costs a catalog call only
        // for what is new. With product updates off nothing the lookup found
        // could be written, so it is not made; the sandbox catalog has nothing
        // to say either way. An ASIN is looked up in a marketplace one of its
        // orders was placed in, so one listed only in Canada is found.
        if (! $sandbox && config('shipment-import.behavior.auto_update_products', true)) {
            $this->catalogCheckedAt = now()->toDateTimeString();

            foreach ($this->asinsNeedingCatalogLookup($rawOrders) as $marketplaceId => $asins) {
                $this->catalogIdentifiers = array_replace($this->catalogIdentifiers, $this->catalogIdentifiers(
                    'Amazon SP-API catalog lookup failed; continuing order import without those barcodes and part numbers',
                    $marketplaceId,
                )->fetch($asins));
            }
        }

        return collect($rawOrders)->map(function (array $order): array {
            $mapped = $this->mapOrderToShipment($order);
            $this->orderCache[$mapped['source_record_id']] = $order;

            return $mapped;
        });
    }

    /**
     * The orders a scheduled import reads (`order-reconciliation/07`): every
     * order updated since the connection last read through, open or closed,
     * in one search. `searchOrders` is throttled hard (a probe of a live account
     * got 31 pages, then a 429, `capacity-audit/02`), so a run that only asks
     * what changed costs one request in the usual case, however many orders
     * are open.
     *
     * Open orders are returned for import. Shipped and cancelled ones are kept
     * for {@see closeRecordsClosedAtSource()}, which closes their Shipments.
     *
     * Every open order within `lookback_days` is read instead when the
     * connection has never read through or last did longer ago than that,
     * and orders that closed in that gap are not seen as closed. It is also
     * read once a day ({@see SWEEP_HOURS}), after the changes and with what
     * budget they leave, to retry an order whose row failed to import, which a
     * search for changes would not offer again until the order changed.
     *
     * A read Amazon throttles stops there: the pages it got are imported, and
     * its query and pagination token are kept so the next run carries on. A
     * finished read records when it started, so an order updated while it was
     * spread over several runs is read again by the next search for changes.
     *
     * Every selected marketplace is read in the one search: `marketplaceIds`
     * takes up to 50.
     *
     * @param  string  $marketplaceIds  comma-separated
     * @return list<array<string, mixed>>
     */
    private function searchChangedOrders(string $marketplaceIds): array
    {
        $now = now()->toImmutable();
        $lookbackStart = $now->subDays((int) ($this->config['lookback_days'] ?? 30));
        $dataSource = $this->dataSource();
        $updatedThrough = $dataSource?->import_updated_through;
        $sweptAt = $dataSource?->import_swept_at;

        /** @var array{changes?: array<string, mixed>|null, sweep?: array<string, mixed>|null} $reads */
        $reads = $dataSource->import_reads ?? [];
        $accountKey = DataSource::importAccountKeyFor($this->config);
        $state = ['import_account_key' => $accountKey];

        // Read from another seller account: start over. An
        // in-flight run that read the old one also records its key, so the
        // run after it starts over too.
        if ($dataSource !== null && $dataSource->import_account_key !== $accountKey) {
            $updatedThrough = null;
            $sweptAt = null;
            $reads = [];
            $state += ['import_updated_through' => null, 'import_swept_at' => null];
        }

        foreach (['changes', 'sweep'] as $kind) {
            if (isset($reads[$kind]) && $this->readHasExpired($reads[$kind], $now)) {
                $reads[$kind] = null;

                // Changes that could not be read within a token's life start
                // over from every open order.
                if ($kind === 'changes') {
                    $updatedThrough = null;
                    $state['import_updated_through'] = null;
                }
            }
        }

        $readsChanges = $updatedThrough !== null && $updatedThrough->greaterThan($lookbackStart);

        if (($reads['changes'] ?? null) === null && $readsChanges) {
            $reads['changes'] = $this->newRead([
                'marketplaceIds' => $marketplaceIds,
                'fulfillmentStatuses' => 'UNSHIPPED,PARTIALLY_SHIPPED,SHIPPED,CANCELLED',
                'lastUpdatedAfter' => $updatedThrough->copy()->subMinutes(self::READ_OVERLAP_MINUTES)->toIso8601String(),
                'includedData' => 'RECIPIENT,PROCEEDS,FULFILLMENT,PACKAGES',
            ], $now);
        }

        if (($reads['sweep'] ?? null) === null
            && (! $readsChanges || $sweptAt === null || $sweptAt->lessThan($now->subHours(self::SWEEP_HOURS)))) {
            $reads['sweep'] = $this->newRead([
                'marketplaceIds' => $marketplaceIds,
                'fulfillmentStatuses' => 'UNSHIPPED,PARTIALLY_SHIPPED',
                'lastUpdatedAfter' => $lookbackStart->toIso8601String(),
                'includedData' => 'RECIPIENT,PROCEEDS,FULFILLMENT',
            ], $now) + ['baseline' => ! $readsChanges];
        }

        /** @var array<string, array<string, mixed>> $orders */
        $orders = [];
        $throttled = false;

        foreach (['changes', 'sweep'] as $kind) {
            $read = $reads[$kind] ?? null;

            if ($read === null || $throttled) {
                continue;
            }

            [$read, $readOrders, $outcome] = $this->continueRead($read, $now, $this->ordersPerRunLimit() - count($orders));

            foreach ($readOrders as $order) {
                $orders[(string) ($order['orderId'] ?? '')] ??= $order;
            }

            $throttled = in_array($outcome, ['throttled', 'limited'], true);
            $reads[$kind] = $throttled ? $read : null;

            if ($outcome !== 'finished') {
                continue;
            }

            $startedAt = CarbonImmutable::parse($read['started_at']);

            if ($kind === 'changes' || ($read['baseline'] ?? false)) {
                $state['import_updated_through'] = $startedAt;
            }

            if ($kind === 'sweep') {
                $state['import_swept_at'] = $startedAt;
            }
        }

        $state['import_reads'] = array_filter($reads) === [] ? null : json_encode(array_filter($reads));
        $this->pendingReadState = $state;
        $open = [];

        foreach ($orders as $orderId => $order) {
            if (in_array($order['fulfillment']['fulfillmentStatus'] ?? null, self::CLOSED_STATUSES, true)) {
                $this->closedOrders[$orderId] = $order;

                continue;
            }

            $open[] = $order;
        }

        return $open;
    }

    /**
     * The most orders one run reads from Amazon, open and closed together.
     */
    private function ordersPerRunLimit(): int
    {
        $limit = (int) config('shipment-import.behavior.max_amazon_orders_per_run');

        return $limit > 0 ? $limit : PHP_INT_MAX;
    }

    /**
     * A search not yet sent.
     *
     * @param  array<string, mixed>  $query
     * @return array{query: array<string, mixed>, token: null, token_at: null, started_at: string}
     */
    private function newRead(array $query, CarbonImmutable $now): array
    {
        return ['query' => $query, 'token' => null, 'token_at' => null, 'started_at' => $now->toIso8601String()];
    }

    /**
     * @param  array<string, mixed>  $read
     */
    private function readHasExpired(array $read, CarbonImmutable $now): bool
    {
        return filled($read['token_at'] ?? null)
            && CarbonImmutable::parse($read['token_at'])->lessThan($now->subHours(self::TOKEN_LIFETIME_HOURS));
    }

    /**
     * Read a search's pages from where it stopped until Amazon has no more or
     * throttles. Amazon refuses a token it no longer honors with a 400, and
     * the read is dropped to start over.
     *
     * Stops early, with the token kept, once it has read `$limit` orders, as it
     * does when throttled: a run holds every order it reads, so how many one
     * run takes is what bounds its memory (`capacity-audit/02`).
     *
     * @param  array<string, mixed>  $read
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: 'finished'|'throttled'|'limited'|'dropped'}
     */
    private function continueRead(array $read, CarbonImmutable $now, int $limit): array
    {
        $orders = [];
        $readCount = 0;

        do {
            if ($readCount >= $limit && $read['token'] !== null) {
                Log::info('An Amazon order search stopped at this run\'s limit; the next run carries on', [
                    'data_source_id' => $this->config['_data_source_id'] ?? null,
                    'orders_read' => $readCount,
                ]);

                return [$read, $this->withoutFbaOrders($orders), 'limited'];
            }

            $query = $read['query'] + ['maxResultsPerPage' => self::SEARCH_ORDERS_PAGE_SIZE];

            if ($read['token'] !== null) {
                $query['paginationToken'] = $read['token'];
            }

            try {
                $json = $this->connector->send(new SearchOrders($query))->json();
            } catch (RequestException $exception) {
                $status = $exception->getResponse()->status();

                if ($status === 429) {
                    return [$read, $this->withoutFbaOrders($orders), 'throttled'];
                }

                if ($status === 400 && $read['token'] !== null) {
                    Log::warning('Amazon refused a stored order search pagination token; the search starts over', [
                        'data_source_id' => $this->config['_data_source_id'] ?? null,
                    ]);

                    return [$read, $this->withoutFbaOrders($orders), 'dropped'];
                }

                throw $exception;
            }

            $page = $this->buyerDataFilter()->filter($json['orders'] ?? []);
            $readCount += count($page);
            array_push($orders, ...$page);
            $read['token'] = $json['pagination']['nextToken'] ?? null;
            $read['token_at'] = $read['token'] === null ? null : $now->toIso8601String();
        } while ($read['token'] !== null);

        return [$read, $this->withoutFbaOrders($orders), 'finished'];
    }

    /**
     * The orders the seller ships, unless the connection imports those Amazon
     * fulfills itself too.
     *
     * @param  list<array<string, mixed>>  $orders
     * @return list<array<string, mixed>>
     */
    private function withoutFbaOrders(array $orders): array
    {
        if ((bool) ($this->config['import_fba_orders'] ?? false)) {
            return $orders;
        }

        $kept = array_values(array_filter($orders, fn (array $order): bool => ! $this->isFulfilledByAmazon($order)));

        if (count($kept) < count($orders)) {
            Log::info('Skipped Amazon-fulfilled (FBA) orders during import', [
                'skipped' => count($orders) - count($kept),
                'imported' => count($kept),
            ]);
        }

        return $kept;
    }

    /**
     * Every page of one search, without the orders Amazon fulfills itself
     * unless the connection imports them.
     *
     * Filtered here rather than in the query: SearchOrders v2026-01-01 was not
     * confirmed to accept a fulfillment-channel parameter, and sending an
     * unrecognized one risks a 400 on every import. Discarding client side
     * costs a little bandwidth.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function searchOrders(array $query, bool $sandbox = false): array
    {
        $importFbaOrders = (bool) ($this->config['import_fba_orders'] ?? false);
        $skippedFbaOrders = 0;
        $rawOrders = [];
        $paginationToken = null;

        do {
            $pageQuery = $query;

            if (! $sandbox) {
                $pageQuery['maxResultsPerPage'] = self::SEARCH_ORDERS_PAGE_SIZE;
            }

            // Orders API v2026 requires subsequent requests to retain the
            // original values, except maxResultsPerPage and includedData.
            // https://developer-docs.amazon.com/sp-api/docs/get-orders-with-filtering-criteria
            if (! $sandbox && $paginationToken !== null) {
                $pageQuery['paginationToken'] = $paginationToken;
            }

            $json = $this->connector->send(new SearchOrders($pageQuery))->json();

            $orders = $this->buyerDataFilter()->filter($json['orders'] ?? []);
            $paginationToken = $sandbox ? null : ($json['pagination']['nextToken'] ?? null);

            foreach ($orders as $order) {
                if (! $importFbaOrders && $this->isFulfilledByAmazon($order)) {
                    $skippedFbaOrders++;

                    continue;
                }

                $rawOrders[] = $order;
            }
        } while ($paginationToken !== null);

        if ($skippedFbaOrders > 0) {
            Log::info('Skipped Amazon-fulfilled (FBA) orders during import', [
                'skipped' => $skippedFbaOrders,
                'imported' => count($rawOrders),
            ]);
        }

        return $rawOrders;
    }

    private function dataSource(): ?DataSource
    {
        $sourceId = $this->config['_data_source_id'] ?? null;

        return $sourceId ? DataSource::query()->find($sourceId) : null;
    }

    /**
     * Whether Amazon ships this order itself. The discriminator lives in the
     * order-level fulfillment block, which is only present when FULFILLMENT is
     * requested in `includedData` — every non-sandbox query above asks for it.
     *
     * @param  array<string, mixed>  $order
     */
    private function isFulfilledByAmazon(array $order): bool
    {
        return ($order['fulfillment']['fulfilledBy'] ?? null) === self::FULFILLED_BY_AMAZON;
    }

    public function fetchShipmentItems(string $sourceRecordId): Collection
    {
        $order = $this->orderCache[$sourceRecordId] ?? null;

        if (! $order) {
            return collect();
        }

        $items = $order['orderItems'] ?? [];

        return collect($items)
            ->map(fn (array $item): array => $this->mapOrderItemToShipmentItem($item))
            ->filter(fn (array $item): bool => $item['quantity'] > 0)
            ->values();
    }

    /**
     * Close the Shipments of the shipped and cancelled orders this run read,
     * through {@see AmazonClosedOrderCloser}, then record how far the
     * connection has read and where a throttled read stopped. Called only
     * after a complete run, so a failed one reads the same pages again.
     *
     * A sandbox import searches something else, and neither closes nor moves
     * anything.
     *
     * @param  Collection<int, array<string, mixed>>  $shipments
     */
    public function closeRecordsClosedAtSource(DataSource $dataSource, Collection $shipments): SourceClosureResult
    {
        if ($this->pendingReadState === null) {
            return new SourceClosureResult;
        }

        $result = app(AmazonClosedOrderCloser::class)->close($dataSource, $this->closedOrders);

        DataSource::query()->whereKey($dataSource->id)->update($this->pendingReadState);

        return $result;
    }

    public function getFieldMapping(): array
    {
        return [];
    }

    public function markExported(string $sourceRecordId): bool
    {
        // No-op: Amazon tracks fulfillment status natively, and a confirmed
        // order comes back as shipped, which closes nothing still open here.
        return false;
    }

    public function getDestinationName(): string
    {
        return 'amazon';
    }

    /**
     * Write the tracking number back to Amazon as a shipment confirmation.
     *
     * Skipped outright for a label bought through Amazon Buy Shipping. That
     * purchase confirms the order as part of buying the postage, and Ship+
     * orders reject a manual `confirmShipment` afterwards with a 400 — so the
     * export's job here is already done, and doing it again would turn a
     * shipped package into a permanently failed export.
     *
     * Gated on the stored Amazon `shipmentId` rather than on the package's
     * postage source: what matters is that *Amazon* bought this label, and the
     * shipment ID is the only thing that says so. A package re-pointed at
     * another data source keeps it, and correctly stays unconfirmed here.
     */
    public function exportPackage(array $data): ?string
    {
        $shipmentReference = filled($data['shipment_reference'] ?? null)
            ? (string) $data['shipment_reference']
            : 'package '.($data['_package_reference_id'] ?? 'unknown');

        // First, ahead of both short-circuits below.
        //
        // Ahead of the credential check because the refusal has to be
        // permanent to mean anything: `validateExportConfiguration()` throws
        // `InvalidArgumentException`, which `PackageExportService` treats as
        // retryable, so a misconfigured source would otherwise retry an FBA
        // package 32 times for a confirmation that can never be valid.
        //
        // Ahead of the Buy Shipping check because both being set is a
        // contradiction — Amazon does not ship an order we bought postage for —
        // and recording that silently as a successful export hides it. Failing
        // loudly surfaces the inconsistent metadata instead.
        if (($data['_amazon_fulfilled_by'] ?? null) === self::FULFILLED_BY_AMAZON) {
            throw new PermanentExportException(
                "Cannot export package for shipment {$shipmentReference}: the order is fulfilled by Amazon (FBA), so Amazon ships it and there is no shipment to confirm."
            );
        }

        // Before the credential check, deliberately. There is nothing to send,
        // so there is nothing credentials are needed for — and a seller who
        // rotates or removes them after the label was bought would otherwise
        // fail an export that had already succeeded at purchase time, leaving a
        // correctly shipped package permanently unexported.
        if (filled($data['_amazon_shipment_id'] ?? null)) {
            Log::info('Skipping Amazon shipment confirmation for a Buy Shipping label', [
                'amazon_shipment_id' => $data['_amazon_shipment_id'],
                'package' => $data['_package_reference_id'] ?? null,
            ]);

            return null;
        }

        $this->validateExportConfiguration();

        $amazonOrderId = $data['amazon_order_id'] ?? null;

        if (empty($amazonOrderId)) {
            throw new InvalidArgumentException(
                "Cannot export package for shipment {$shipmentReference}: no Amazon order ID in metadata."
            );
        }

        $sandbox = app(AmazonSpApiEnvironment::class)->usesSandbox(ConfirmShipment::class);
        $carrier = trim((string) ($data['carrier'] ?? ''));
        $carrierCode = self::CARRIER_MAP[$carrier] ?? self::CARRIER_MAP[strtoupper($carrier)] ?? 'Other';

        if ($sandbox) {
            // Sandbox requires exact pattern-matched values for a 204 response
            $orderId = '902-1106328-1059050';
            $body = [
                'marketplaceId' => 'ATVPDKIKX0DER',
                'packageDetail' => [
                    'packageReferenceId' => '1',
                    'carrierCode' => 'FedEx',
                    'carrierName' => 'FedEx',
                    'shippingMethod' => 'FedEx Ground',
                    'trackingNumber' => '112345678',
                    'shipDate' => '2022-02-11T01:00:00.000Z',
                    'shipFromSupplySourceId' => '057d3fcc-b750-419f-bbcd-4d340c60c430',
                    'orderItems' => [
                        [
                            'orderItemId' => '79039765272157',
                            'quantity' => 1,
                            'transparencyCodes' => ['09876543211234567890'],
                        ],
                    ],
                ],
            ];
        } else {
            $orderItems = $data['_order_items'] ?? [];

            foreach (['tracking_number', 'carrier', '_package_reference_id', '_shipped_at'] as $requiredField) {
                if (blank($data[$requiredField] ?? null)) {
                    throw new InvalidArgumentException(
                        "Cannot export package for shipment {$shipmentReference}: {$requiredField} is missing."
                    );
                }
            }

            if ($orderItems === []) {
                throw new PermanentExportException(
                    "Cannot export package for shipment {$shipmentReference}: no Amazon order items were packed. Re-import the order before retrying."
                );
            }

            $orderId = $amazonOrderId;
            $body = [
                // The order's own marketplace: one connection reads several.
                'marketplaceId' => filled($data['_amazon_marketplace_id'] ?? null)
                    ? (string) $data['_amazon_marketplace_id']
                    : $this->primaryMarketplaceId(),
                'packageDetail' => [
                    'packageReferenceId' => (string) $data['_package_reference_id'],
                    'carrierCode' => $carrierCode,
                    ...($carrierCode === 'Other' ? ['carrierName' => $carrier] : []),
                    ...(filled($data['_shipping_method'] ?? null) ? ['shippingMethod' => $data['_shipping_method']] : []),
                    'trackingNumber' => $data['tracking_number'],
                    'shipDate' => $data['_shipped_at'],
                    'orderItems' => $orderItems,
                ],
            ];
        }

        try {
            $response = $this->connector->send(
                new ConfirmShipment($orderId, $body)
            );
        } catch (RequestException $exception) {
            $response = $exception->getResponse();
        }

        if (! $response->successful()) {
            $json = $response->json();
            $errors = $json['errors'] ?? [];
            $messages = array_map(
                fn (array $e): string => ($e['code'] ?? 'unknown').': '.($e['message'] ?? ''),
                $errors
            );
            $message = 'Amazon shipment confirmation error: '.implode('; ', $messages);
            $status = $response->status();

            if ($this->shipmentWasAlreadyConfirmed($errors)) {
                return null;
            }

            if ($status >= 400 && $status < 500 && ! in_array($status, [401, 403, 408, 425, 429], true)) {
                throw new PermanentExportException($message);
            }

            throw new RuntimeException($message);
        }

        return null;
    }

    /** @param list<array<string, mixed>> $errors */
    private function shipmentWasAlreadyConfirmed(array $errors): bool
    {
        if ($errors === []) {
            return false;
        }

        $codes = [
            'AlreadyShipped',
            'DuplicateShipmentConfirmation',
            'OrderAlreadyShipped',
            'ShipmentAlreadyConfirmed',
        ];
        $messageFragments = [
            'already been confirmed',
            'already been fulfilled',
            'already been shipped',
            'already confirmed',
            'already fulfilled',
            'already shipped',
        ];

        foreach ($errors as $error) {
            if (in_array((string) ($error['code'] ?? ''), $codes, true)) {
                continue;
            }

            $message = strtolower((string) ($error['message'] ?? ''));
            $matchesKnownMessage = false;

            foreach ($messageFragments as $fragment) {
                if (str_contains($message, $fragment)) {
                    $matchesKnownMessage = true;
                    break;
                }
            }

            if (! $matchesKnownMessage) {
                return false;
            }
        }

        return true;
    }

    public function validateExportConfiguration(): void
    {
        $usesBrokerOAuth = ($this->config['auth_mode'] ?? null) === 'authorization_code';
        $hasOwnCredentials = filled($this->config['client_id'] ?? null)
            && filled($this->config['client_secret'] ?? null);

        if ((! $usesBrokerOAuth && ! $hasOwnCredentials) || empty($this->config['refresh_token'] ?? null)) {
            throw new InvalidArgumentException('Amazon SP-API credentials are not configured for this source.');
        }

        if (DataSource::primaryAmazonMarketplaceIdIn($this->config) === null) {
            throw new InvalidArgumentException('Amazon SP-API marketplace ID is not configured for this source.');
        }
    }

    private function mapOrderToShipment(array $order): array
    {
        $address = $order['recipient']['deliveryAddress'] ?? [];

        // Already reduced to where the order is going when it was read.
        if ($this->buyerDataFilter()->withholds()) {
            $address['addressLine1'] = Shipment::WITHHELD_ADDRESS_LINE;
        }

        $items = $order['orderItems'] ?? [];
        $recipientAvailable = filled($address['addressLine1'] ?? null) && filled($address['city'] ?? null);
        $addressType = is_string($address['addressType'] ?? null) ? $address['addressType'] : null;
        $residential = match ($addressType) {
            'RESIDENTIAL' => true,
            'COMMERCIAL' => false,
            default => null,
        };
        $preserveExistingFields = ['email'];

        // Sum line item values using proceeds breakdowns.
        $useOrderedQuantity = $this->readsFromSandbox();
        $totalValue = 0;
        foreach ($items as $item) {
            $qtyOrdered = (int) ($item['quantityOrdered'] ?? 0);
            $qty = $useOrderedQuantity
                ? $qtyOrdered
                : max(0, $qtyOrdered - (int) ($item['fulfillment']['quantityFulfilled'] ?? 0));

            $itemTotal = $this->sumItemProceeds($item);
            $unitPrice = $qtyOrdered > 0 ? $itemTotal / $qtyOrdered : 0;
            $totalValue += $unitPrice * $qty;
        }

        // Split name into first/last. No name, as in Demo, is no first name
        // rather than an empty one.
        $fullName = trim((string) ($address['name'] ?? ''));
        $nameParts = $fullName === '' ? [] : preg_split('/\s+/', $fullName, 2);
        $firstName = $nameParts[0] ?? null;
        $lastName = $nameParts[1] ?? null;

        // Orders v2026-01-01 moved the order status and the buyer's selected
        // shipping speed into the order-level fulfillment block, which is only
        // present when FULFILLMENT is requested in includedData.
        $fulfillment = $order['fulfillment'] ?? [];
        $serviceLevel = $fulfillment['fulfillmentServiceLevel'] ?? null;
        $defaultShippingMethod = $this->config['shipping_method'] ?? null;

        return [
            'source_record_id' => $order['orderId'] ?? '',
            'shipment_reference' => $order['orderId'] ?? '',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company' => null,
            'address1' => $address['addressLine1'] ?? null,
            'address2' => $address['addressLine2'] ?? null,
            'city' => $address['city'] ?? null,
            'state_or_province' => $address['stateOrRegion'] ?? null,
            'postal_code' => $address['postalCode'] ?? null,
            'country' => $address['countryCode'] ?? 'US',
            'phone' => $address['phone'] ?? null,
            'email' => null,
            'value' => round($totalValue, 2),
            'residential' => $residential,
            'channel_id' => $this->config['channel_name'] ?? 'Amazon',
            'shipping_method_id' => filled($serviceLevel) ? $serviceLevel : $defaultShippingMethod,
            '_shipping_method_fallback' => $defaultShippingMethod,
            'deliver_by' => Location::localDateFromTimestamp($fulfillment['deliverByWindow']['latestDateTime'] ?? null),
            'ship_by' => Location::localDateFromTimestamp($fulfillment['shipByWindow']['latestDateTime'] ?? null),
            '_preserve_existing_fields' => $preserveExistingFields,
            'metadata' => [
                'amazon_order_id' => $order['orderId'] ?? null,
                // Order-level only, as Amazon spells them. Items carry their own
                // `programs` (TRANSPARENCY, SUBSCRIBE_AND_SAVE), which are not
                // delivery programs. [] means imported with none; a missing key
                // means imported before programs were recorded.
                'amazon_programs' => array_values(array_filter(
                    is_array($order['programs'] ?? null) ? $order['programs'] : [],
                    'is_string',
                )),
                'amazon_order_status' => $fulfillment['fulfillmentStatus'] ?? null,
                'amazon_created_time' => $order['createdTime'] ?? null,
                'amazon_sales_channel' => $order['salesChannel'] ?? null,
                'amazon_address_type' => $addressType,
                'amazon_fulfilled_by' => $fulfillment['fulfilledBy'] ?? null,
                'amazon_fulfillment_service_level' => $serviceLevel,
                'amazon_ship_by_window' => $fulfillment['shipByWindow'] ?? null,
                'amazon_deliver_by_window' => $fulfillment['deliverByWindow'] ?? null,
                'amazon_asins' => collect($items)->pluck('product.asin')->filter()->unique()->values()->all(),
                'amazon_packages' => $order['packages'] ?? [],
                'amazon_recipient_available' => $recipientAvailable,
            ],
        ];
    }

    private function mapOrderItemToShipmentItem(array $item): array
    {
        $qtyOrdered = (int) ($item['quantityOrdered'] ?? 0);
        $qtyRemaining = $this->readsFromSandbox()
            ? $qtyOrdered
            : max(0, $qtyOrdered - (int) ($item['fulfillment']['quantityFulfilled'] ?? 0));

        $itemTotal = $this->sumItemProceeds($item);
        $unitPrice = $qtyOrdered > 0 ? $itemTotal / $qtyOrdered : 0;
        $asin = $item['product']['asin'] ?? null;
        $catalog = is_string($asin) ? ($this->catalogIdentifiers[$asin] ?? null) : null;

        return [
            'sku' => $item['product']['sellerSku'] ?? null,
            'name' => $item['product']['title'] ?? null,
            'source_item_id' => $item['orderItemId'] ?? null,
            'quantity' => $qtyRemaining,
            'value' => round($unitPrice, 2),
            'barcode' => $catalog['barcode'] ?? null,
            'manufacturer_part_number' => $catalog['part_number'] ?? null,
            'identifiers_checked_at' => $catalog === null ? null : $this->catalogCheckedAt,
            '_fill_only' => AmazonCatalogIdentifiers::FILL_ONLY_FIELDS,
            'weight' => null,
        ];
    }

    /**
     * The ASINs in these orders the catalog should be asked about: those where
     * some SKU has no Product yet, or a Product missing its barcode or part
     * number that the catalog has not answered for within the recheck window.
     *
     * Products are filed by seller SKU (`ImportReferenceResolver::productIdFor()`),
     * and several SKUs can list under one ASIN, so an ASIN is looked up when
     * *any* of its SKUs needs it — one complete SKU must not hide the lookup
     * from another. An item with no SKU never reaches a Product, so nothing the
     * lookup found could be filled or stamped, and it does not count.
     *
     * Grouped by marketplace, so each ASIN is asked of a catalog it is listed
     * in: the primary when any order it came in was placed there, else the
     * marketplace of the first order it came in. Each ASIN is looked up once,
     * so a second marketplace's empty answer can never overwrite the first's.
     *
     * @param  array<int, array<string, mixed>>  $orders
     * @return array<string, Collection<int, string>>
     */
    private function asinsNeedingCatalogLookup(array $orders): array
    {
        $primaryMarketplaceId = $this->primaryMarketplaceId();

        /** @var array<string, list<string>> $skusByAsin */
        $skusByAsin = [];

        /** @var array<string, string> $marketplaceByAsin */
        $marketplaceByAsin = [];

        foreach ($orders as $order) {
            $marketplaceId = $order['salesChannel']['marketplaceId'] ?? null;
            $marketplaceId = is_string($marketplaceId) && $marketplaceId !== '' ? $marketplaceId : $primaryMarketplaceId;

            foreach ($order['orderItems'] ?? [] as $item) {
                $asin = is_array($item) ? ($item['product']['asin'] ?? null) : null;
                $sku = is_array($item) ? ($item['product']['sellerSku'] ?? null) : null;

                if (! is_string($asin) || $asin === '' || ! is_string($sku) || $sku === '') {
                    continue;
                }

                $skusByAsin[$asin] = array_values(array_unique([...($skusByAsin[$asin] ?? []), $sku]));

                if (! isset($marketplaceByAsin[$asin]) || $marketplaceId === $primaryMarketplaceId) {
                    $marketplaceByAsin[$asin] = $marketplaceId;
                }
            }
        }

        $asinsByMarketplace = [];

        foreach (AmazonCatalogIdentifiers::asinsNeedingLookup($skusByAsin, $this->importClientId()) as $asin) {
            $marketplaceId = $marketplaceByAsin[$asin];
            $asinsByMarketplace[$marketplaceId] = ($asinsByMarketplace[$marketplaceId] ?? collect())->push($asin);
        }

        return $asinsByMarketplace;
    }

    /**
     * The catalog lookup, on this run's request pacing, in a marketplace:
     * an order's own during an import, else the connection's primary.
     *
     * @param  string  $failureMessage  what a failed lookup logs, saying what carries on without it
     */
    public function catalogIdentifiers(string $failureMessage, ?string $marketplaceId = null): AmazonCatalogIdentifiers
    {
        return new AmazonCatalogIdentifiers(
            $this->requests,
            $marketplaceId ?? $this->primaryMarketplaceId(),
            isset($this->config['_data_source_id']) ? (int) $this->config['_data_source_id'] : null,
            $failureMessage,
        );
    }

    /**
     * Every listing of the seller account, a page at a time
     * (`product-catalog-sync/04`), through {@see AmazonListingCatalog}.
     * Listing search is by seller ID, which only an OAuth connection records.
     *
     * @return Generator<int, ProductCatalogPage>
     *
     * @throws CatalogReadMustStartOver when the connection has no seller ID, or Amazon refuses a search
     */
    public function fetchProductCatalogPages(?string $cursor = null): Generator
    {
        $sellerId = $this->config['amazon_selling_partner_id'] ?? null;

        if (($this->config['auth_mode'] ?? null) !== 'authorization_code' || ! is_string($sellerId) || $sellerId === '') {
            throw new CatalogReadMustStartOver('Connect this Amazon account through OAuth to sync its product catalog: listing search needs the seller ID that OAuth records.');
        }

        if (blank($this->config['refresh_token'] ?? null)) {
            throw new InvalidArgumentException('Amazon SP-API refresh token is not configured for this source.');
        }

        $primaryMarketplaceId = DataSource::primaryAmazonMarketplaceIdIn($this->config);

        if ($primaryMarketplaceId === null) {
            throw new InvalidArgumentException('Choose an Amazon marketplace before syncing the product catalog.');
        }

        yield from (new AmazonListingCatalog($this->requests, $sellerId, $primaryMarketplaceId))->pages($cursor);
    }

    /**
     * The connection's primary marketplace, for a call with no order to take
     * one from. The US when none is chosen, as before there was a choice.
     */
    private function primaryMarketplaceId(): string
    {
        return DataSource::primaryAmazonMarketplaceIdIn($this->config) ?? AmazonMarketplace::UnitedStates->value;
    }

    /**
     * Record the page's SKUs and ASINs for the catalog look-up that follows
     * the listings.
     */
    public function productCatalogPageWritten(DataSource $dataSource, ProductCatalogPage $page): void
    {
        app(AmazonCatalogLookups::class)->record($dataSource, $page);
    }

    /**
     * Queue the catalog look-up for the SKUs the run listed. It runs on the
     * `imports` queue in short slices, as the sync did.
     */
    public function productCatalogSyncFinished(DataSource $dataSource, ProductCatalogSyncRun $run): void
    {
        if (AmazonCatalogLookup::query()->where('data_source_id', $dataSource->id)->exists()) {
            LookUpAmazonCatalogIdentifiersJob::dispatch($dataSource->id);
        }
    }

    /**
     * The client this source imports for, which is the client its products
     * are filed under.
     */
    private function importClientId(): int
    {
        $sourceId = $this->config['_data_source_id'] ?? null;
        $clientId = $sourceId ? DataSource::query()->whereKey($sourceId)->value('client_id') : null;

        return (int) ($clientId ?? app(ClientContext::class)->default()->id);
    }

    /**
     * Whether the order search returns Amazon's static sandbox test cases
     * rather than the seller's orders. Asked of the same place the connector
     * picks its host from, so the two never disagree: in Demo the orders are
     * read from production and are real, though postage is on the sandbox.
     */
    private function readsFromSandbox(): bool
    {
        return app(AmazonSpApiEnvironment::class)->readsFromSandbox();
    }

    private function buyerDataFilter(): AmazonBuyerDataFilter
    {
        return app(AmazonBuyerDataFilter::class);
    }

    /**
     * Sum the ITEM-type subtotals from an order item's proceeds breakdowns.
     */
    private function sumItemProceeds(array $item): float
    {
        $breakdowns = $item['proceeds']['breakdowns'] ?? [];
        $total = 0;

        foreach ($breakdowns as $breakdown) {
            if (($breakdown['type'] ?? '') === 'ITEM') {
                $total += (float) ($breakdown['subtotal']['amount'] ?? 0);
            }
        }

        return $total;
    }
}
