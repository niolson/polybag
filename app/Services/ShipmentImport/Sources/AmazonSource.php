<?php

namespace App\Services\ShipmentImport\Sources;

use App\Contracts\DataSourceInterface;
use App\Contracts\ExportDestinationInterface;
use App\Enums\ShipmentStatus;
use App\Exceptions\PermanentExportException;
use App\Http\Integrations\Amazon\AmazonSpApiConnector;
use App\Http\Integrations\Amazon\Requests\ConfirmShipment;
use App\Http\Integrations\Amazon\Requests\SearchCatalogItems;
use App\Http\Integrations\Amazon\Requests\SearchOrders;
use App\Models\Location;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Response;
use Throwable;

class AmazonSource implements DataSourceInterface, ExportDestinationInterface
{
    public const HISTORICAL_IMPORT_MAX_ORDERS = 1000;

    /**
     * `fulfillment.fulfilledBy` when Amazon ships the order from its own
     * warehouse (FBA) rather than the seller shipping it (MFN).
     */
    public const FULFILLED_BY_AMAZON = 'AMAZON';

    private array $config;

    private AmazonSpApiConnector $connector;

    /** @var array<string, array> Cached order data keyed by source record ID */
    private array $orderCache = [];

    /** @var array<string, string> Catalog barcode keyed by ASIN */
    private array $catalogBarcodes = [];

    private float $catalogRequestRate = 2.0;

    private bool $hasSentCatalogRequest = false;

    private const CATALOG_IDENTIFIER_PRIORITY = ['UPC', 'EAN', 'GTIN', 'JAN', 'ISBN'];

    private const CATALOG_MAX_ATTEMPTS = 5;

    private const SEARCH_ORDERS_PAGE_SIZE = 100;

    private const CARRIER_MAP = [
        'USPS' => 'USPS',
        'FEDEX' => 'FedEx',
        'FedEx' => 'FedEx',
        'UPS' => 'UPS',
        'DHL' => 'DHL',
    ];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connector = AmazonSpApiConnector::fromSettings($config);
    }

    public function validateConfiguration(): void
    {
        if (! app(SettingsService::class)->get('require_mfa')) {
            throw new RuntimeException('Multi-factor authentication must be enabled to use Amazon SP-API imports. Enable it in App Settings → Authentication.');
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

        if (empty($this->config['marketplace_id'] ?? null)) {
            throw new InvalidArgumentException('Amazon SP-API marketplace ID is not configured for this source.');
        }

        if (empty($this->config['channel_name'])) {
            throw new InvalidArgumentException('Amazon channel name is not configured.');
        }

        if ($this->isHistoricalImport()) {
            if ((bool) app(SettingsService::class)->get('sandbox_mode', false)) {
                throw new RuntimeException('Historical Amazon order imports require production mode. Disable sandbox mode in App Settings and try again.');
            }

            $createdAfter = $this->config['_historical_created_after'] ?? null;
            $createdBefore = $this->config['_historical_created_before'] ?? null;
            $maxOrders = (int) ($this->config['_historical_max_orders'] ?? 0);

            if (! is_string($createdAfter) || ! is_string($createdBefore) || strtotime($createdAfter) === false || strtotime($createdBefore) === false) {
                throw new InvalidArgumentException('Historical Amazon imports require a valid start and end date.');
            }

            if (strtotime($createdAfter) > strtotime($createdBefore)) {
                throw new InvalidArgumentException('Historical Amazon import start date must be before the end date.');
            }

            if ($maxOrders < 1 || $maxOrders > self::HISTORICAL_IMPORT_MAX_ORDERS) {
                throw new InvalidArgumentException('Historical Amazon imports are limited to 1–'.self::HISTORICAL_IMPORT_MAX_ORDERS.' orders.');
            }
        }
    }

    public function fetchShipments(): Collection
    {
        $this->orderCache = [];
        $this->catalogBarcodes = [];
        $this->catalogRequestRate = 2.0;
        $this->hasSentCatalogRequest = false;
        $rawOrders = [];
        $paginationToken = null;
        $sandbox = (bool) app(SettingsService::class)->get('sandbox_mode', false);
        $marketplaceId = (string) ($this->config['marketplace_id'] ?? 'ATVPDKIKX0DER');
        $lookbackDays = $this->config['lookback_days'] ?? 30;
        $lastUpdatedAfter = now()->subDays($lookbackDays)->toIso8601String();
        $historicalImport = $this->isHistoricalImport();
        $maxOrders = $historicalImport ? (int) $this->config['_historical_max_orders'] : null;

        // Filtered here rather than in the query: SearchOrders v2026-01-01 was
        // not confirmed to accept a fulfillment-channel parameter, and sending
        // an unrecognised one risks a 400 on every import. Discarding client
        // side costs a little bandwidth but keeps `maxResultsPerPage` honest,
        // because the cap below counts orders we keep, not orders we fetched.
        $importFbaOrders = (bool) ($this->config['import_fba_orders'] ?? false);
        $skippedFbaOrders = 0;

        do {
            $maxResultsPerPage = $maxOrders === null
                ? self::SEARCH_ORDERS_PAGE_SIZE
                : min(self::SEARCH_ORDERS_PAGE_SIZE, $maxOrders - count($rawOrders));

            if ($sandbox) {
                // v2026-01-01 sandbox test cases only exist for JP/UK/BR marketplaces.
                // Test Case 1: Japan marketplace with all includedData values.
                //
                // A1VC38T7YXB528 is Amazon's JP marketplace ID, which only resolves
                // against the FE sandbox host — hence SearchOrders declaring the FE
                // region. Sending this marketplace ID to the NA sandbox host 403s with
                // "The marketplaces you provided are not valid for region"
                // (see https://github.com/amzn/selling-partner-api-models/issues/5126).
                $query = [
                    'marketplaceIds' => 'A1VC38T7YXB528',
                    'createdAfter' => '2024-12-25T00:00:00Z',
                    'includedData' => 'BUYER,RECIPIENT,PROCEEDS,EXPENSE,PROMOTION,CANCELLATION,FULFILLMENT,PACKAGES',
                ];
            } elseif ($historicalImport) {
                $query = [
                    'marketplaceIds' => $marketplaceId,
                    'fulfillmentStatuses' => 'SHIPPED',
                    'createdAfter' => (string) $this->config['_historical_created_after'],
                    'createdBefore' => (string) $this->config['_historical_created_before'],
                    'maxResultsPerPage' => $maxResultsPerPage,
                    'includedData' => 'RECIPIENT,PROCEEDS,FULFILLMENT,PACKAGES',
                ];
            } else {
                $query = [
                    'marketplaceIds' => $marketplaceId,
                    'fulfillmentStatuses' => 'UNSHIPPED,PARTIALLY_SHIPPED',
                    'lastUpdatedAfter' => $lastUpdatedAfter,
                    'maxResultsPerPage' => self::SEARCH_ORDERS_PAGE_SIZE,
                    'includedData' => 'RECIPIENT,PROCEEDS,FULFILLMENT',
                ];
            }

            // Orders API v2026 requires subsequent requests to retain the
            // original values, except maxResultsPerPage and includedData.
            // https://developer-docs.amazon.com/sp-api/docs/get-orders-with-filtering-criteria
            if (! $sandbox && $paginationToken !== null) {
                $query['paginationToken'] = $paginationToken;
            }

            $response = $this->connector->send(new SearchOrders($query));
            $json = $response->json();

            $orders = $json['orders'] ?? [];
            $paginationToken = $sandbox ? null : ($json['pagination']['nextToken'] ?? null);

            foreach ($orders as $order) {
                if ($maxOrders !== null && count($rawOrders) >= $maxOrders) {
                    $paginationToken = null;

                    break;
                }

                if (! $importFbaOrders && $this->isFulfilledByAmazon($order)) {
                    $skippedFbaOrders++;

                    continue;
                }

                $rawOrders[] = $order;
            }

            if ($maxOrders !== null && count($rawOrders) >= $maxOrders) {
                $paginationToken = null;
            }
        } while ($paginationToken !== null);

        if ($skippedFbaOrders > 0) {
            Log::info('Skipped Amazon-fulfilled (FBA) orders during import', [
                'skipped' => $skippedFbaOrders,
                'imported' => count($rawOrders),
            ]);
        }

        if (! $sandbox && $historicalImport) {
            $this->catalogBarcodes = $this->fetchCatalogBarcodes($rawOrders, $marketplaceId);
        }

        return collect($rawOrders)->map(function (array $order): array {
            $mapped = $this->mapOrderToShipment($order);
            $this->orderCache[$mapped['source_record_id']] = $order;

            return $mapped;
        });
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

    public function getFieldMapping(): array
    {
        return [];
    }

    public function markExported(string $sourceRecordId): bool
    {
        // No-op: Amazon tracks fulfillment status natively.
        // Fulfilled orders won't match the Unshipped filter on next import.
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
    public function exportPackage(array $data): void
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

            return;
        }

        $this->validateExportConfiguration();

        $amazonOrderId = $data['amazon_order_id'] ?? null;

        if (empty($amazonOrderId)) {
            throw new InvalidArgumentException(
                "Cannot export package for shipment {$shipmentReference}: no Amazon order ID in metadata."
            );
        }

        $sandbox = (bool) app(SettingsService::class)->get('sandbox_mode', false);
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
                'marketplaceId' => (string) ($this->config['marketplace_id'] ?? 'ATVPDKIKX0DER'),
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
                return;
            }

            if ($status >= 400 && $status < 500 && ! in_array($status, [401, 403, 408, 425, 429], true)) {
                throw new PermanentExportException($message);
            }

            throw new RuntimeException($message);
        }
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

        if (blank($this->config['marketplace_id'] ?? null)) {
            throw new InvalidArgumentException('Amazon SP-API marketplace ID is not configured for this source.');
        }
    }

    private function mapOrderToShipment(array $order): array
    {
        $address = $order['recipient']['deliveryAddress'] ?? [];
        $items = $order['orderItems'] ?? [];
        $recipientAvailable = filled($address['addressLine1'] ?? null) && filled($address['city'] ?? null);
        $preserveExistingFields = ['email'];

        if ($this->isHistoricalImport() && ! $recipientAvailable) {
            $address['addressLine1'] = '[Unavailable from Amazon]';
            $address['city'] = '[Unavailable]';
            $preserveExistingFields = array_merge($preserveExistingFields, [
                'first_name', 'last_name', 'company', 'address1', 'address2', 'city',
                'state_or_province', 'postal_code', 'country', 'phone', 'phone_e164',
                'phone_extension',
            ]);
        }

        // Sum line item values using proceeds breakdowns.
        $sandbox = (bool) app(SettingsService::class)->get('sandbox_mode', false);
        $useOrderedQuantity = $sandbox || $this->isHistoricalImport();
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

        // Split name into first/last
        $fullName = $address['name'] ?? '';
        $nameParts = preg_split('/\s+/', trim($fullName), 2);
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
            'channel_id' => $this->config['channel_name'] ?? 'Amazon',
            'shipping_method_id' => filled($serviceLevel) ? $serviceLevel : $defaultShippingMethod,
            '_shipping_method_fallback' => $defaultShippingMethod,
            'deliver_by' => $this->localDateFromTimestamp($fulfillment['deliverByWindow']['latestDateTime'] ?? null),
            '_import_status' => $this->isHistoricalImport() ? ShipmentStatus::Shipped->value : ShipmentStatus::Open->value,
            '_preserve_existing_fields' => $preserveExistingFields,
            'metadata' => [
                'amazon_order_id' => $order['orderId'] ?? null,
                'amazon_order_status' => $fulfillment['fulfillmentStatus'] ?? null,
                'amazon_created_time' => $order['createdTime'] ?? null,
                'amazon_sales_channel' => $order['salesChannel'] ?? null,
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

    /**
     * Convert an Amazon ISO 8601 timestamp to a calendar date in the default
     * location's timezone. Amazon quotes promise windows in UTC, so taking the
     * raw UTC date would push an evening-local deadline onto the next day.
     */
    private function localDateFromTimestamp(mixed $timestamp): ?string
    {
        if (! is_string($timestamp) || trim($timestamp) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($timestamp)
                ->setTimezone(Location::timezone())
                ->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function mapOrderItemToShipmentItem(array $item): array
    {
        $qtyOrdered = (int) ($item['quantityOrdered'] ?? 0);
        $sandbox = (bool) app(SettingsService::class)->get('sandbox_mode', false);
        $qtyRemaining = ($sandbox || $this->isHistoricalImport())
            ? $qtyOrdered
            : max(0, $qtyOrdered - (int) ($item['fulfillment']['quantityFulfilled'] ?? 0));

        $itemTotal = $this->sumItemProceeds($item);
        $unitPrice = $qtyOrdered > 0 ? $itemTotal / $qtyOrdered : 0;
        $asin = $item['product']['asin'] ?? null;

        return [
            'sku' => $item['product']['sellerSku'] ?? null,
            'name' => $item['product']['title'] ?? null,
            'source_item_id' => $item['orderItemId'] ?? null,
            'quantity' => $qtyRemaining,
            'value' => round($unitPrice, 2),
            'barcode' => is_string($asin) ? ($this->catalogBarcodes[$asin] ?? null) : null,
            '_fill_missing_barcode_only' => true,
            'weight' => null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $orders
     * @return array<string, string>
     */
    private function fetchCatalogBarcodes(array $orders, string $marketplaceId): array
    {
        $asins = collect($orders)
            ->flatMap(fn (array $order): array => $order['orderItems'] ?? [])
            ->map(fn (array $item): mixed => $item['product']['asin'] ?? null)
            ->filter(fn (mixed $asin): bool => is_string($asin) && $asin !== '')
            ->unique()
            ->values();

        $barcodes = [];

        foreach ($asins->chunk(20) as $asinChunk) {
            try {
                $response = $this->sendCatalogRequest(new SearchCatalogItems([
                    'marketplaceIds' => $marketplaceId,
                    'identifiersType' => 'ASIN',
                    'identifiers' => $asinChunk->implode(','),
                    'includedData' => 'identifiers',
                ]));

                if ($response->failed()) {
                    $this->logCatalogLookupFailure($asinChunk->count(), 'Amazon returned HTTP '.$response->status().'.');

                    continue;
                }

                foreach ($response->json('items', []) as $catalogItem) {
                    if (! is_array($catalogItem)) {
                        continue;
                    }

                    $asin = $catalogItem['asin'] ?? null;
                    $barcode = $this->preferredCatalogBarcode($catalogItem, $marketplaceId);

                    if (is_string($asin) && $asin !== '' && $barcode !== null) {
                        $barcodes[$asin] = $barcode;
                    }
                }
            } catch (Throwable $exception) {
                $this->logCatalogLookupFailure($asinChunk->count(), $exception->getMessage());
            }
        }

        return $barcodes;
    }

    private function sendCatalogRequest(SearchCatalogItems $request): Response
    {
        $retryDelayMilliseconds = null;

        for ($attempt = 1; $attempt <= self::CATALOG_MAX_ATTEMPTS; $attempt++) {
            if ($this->hasSentCatalogRequest) {
                Sleep::for($retryDelayMilliseconds ?? $this->catalogRequestIntervalMilliseconds())->milliseconds();
            }

            $this->hasSentCatalogRequest = true;
            $response = $this->connector->send($request);
            $this->updateCatalogRequestRate($response);

            if ($response->status() !== 429 || $attempt === self::CATALOG_MAX_ATTEMPTS) {
                return $response;
            }

            $retryDelayMilliseconds = $this->catalogRetryDelayMilliseconds($response, $attempt);
        }

        throw new RuntimeException('Amazon catalog request retry loop ended unexpectedly.');
    }

    private function updateCatalogRequestRate(Response $response): void
    {
        $rateLimit = $response->header('x-amzn-RateLimit-Limit');

        if (is_array($rateLimit)) {
            $rateLimit = $rateLimit[0] ?? null;
        }

        if (is_string($rateLimit) && is_numeric($rateLimit) && (float) $rateLimit > 0) {
            $this->catalogRequestRate = (float) $rateLimit;
        }
    }

    private function catalogRequestIntervalMilliseconds(): int
    {
        return max(1, (int) ceil(1000 / $this->catalogRequestRate));
    }

    private function catalogRetryDelayMilliseconds(Response $response, int $attempt): int
    {
        $retryAfter = $response->header('Retry-After');

        if (is_array($retryAfter)) {
            $retryAfter = $retryAfter[0] ?? null;
        }

        if (is_string($retryAfter) && is_numeric($retryAfter)) {
            return max($this->catalogRequestIntervalMilliseconds(), (int) ceil((float) $retryAfter * 1000));
        }

        if (is_string($retryAfter) && ($retryAt = strtotime($retryAfter)) !== false) {
            return max($this->catalogRequestIntervalMilliseconds(), ($retryAt - time()) * 1000);
        }

        return $this->catalogRequestIntervalMilliseconds() * (2 ** ($attempt - 1));
    }

    /**
     * @param  array<string, mixed>  $catalogItem
     */
    private function preferredCatalogBarcode(array $catalogItem, string $marketplaceId): ?string
    {
        $identifierGroups = collect($catalogItem['identifiers'] ?? []);
        $marketplaceGroups = $identifierGroups->where('marketplaceId', $marketplaceId);

        if ($marketplaceGroups->isEmpty()) {
            $marketplaceGroups = $identifierGroups;
        }

        $identifiers = $marketplaceGroups
            ->flatMap(fn (mixed $group): array => is_array($group) ? ($group['identifiers'] ?? []) : [])
            ->filter(fn (mixed $identifier): bool => is_array($identifier));

        foreach (self::CATALOG_IDENTIFIER_PRIORITY as $identifierType) {
            $identifier = $identifiers->first(
                fn (array $identifier): bool => strtoupper((string) ($identifier['identifierType'] ?? '')) === $identifierType
                    && is_string($identifier['identifier'] ?? null)
                    && $identifier['identifier'] !== ''
            );

            if (is_array($identifier)) {
                return $identifier['identifier'];
            }
        }

        return null;
    }

    private function logCatalogLookupFailure(int $asinCount, string $error): void
    {
        Log::channel((string) config('shipment-import.logging.channel', 'shipment-import'))
            ->warning('Amazon SP-API catalog barcode lookup failed; continuing order import without those barcodes', [
                'data_source_id' => $this->config['_data_source_id'] ?? null,
                'asin_count' => $asinCount,
                'error' => $error,
            ]);
    }

    private function isHistoricalImport(): bool
    {
        return ($this->config['_historical_import'] ?? false) === true;
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
