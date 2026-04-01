<?php

namespace App\Services\ShipmentImport\Sources;

use App\Contracts\ExportDestinationInterface;
use App\Contracts\ImportSourceInterface;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Http\Integrations\Shopify\ShopifyConnector;
use App\Services\SettingsService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class ShopifySource implements ExportDestinationInterface, ImportSourceInterface
{
    private array $config;

    private ShopifyConnector $connector;

    /** @var array<string, array> Cached order data keyed by source record ID */
    private array $orderCache = [];

    private const CARRIER_MAP = [
        'USPS' => 'USPS',
        'FedEx' => 'FedEx',
        'UPS' => 'UPS',
        'DHL' => 'DHL Express',
    ];

    private const ORDERS_QUERY = <<<'GRAPHQL'
        query UnfulfilledOrders($cursor: String) {
          orders(first: 250, after: $cursor, query: "fulfillment_status:unfulfilled") {
            pageInfo { hasNextPage endCursor }
            nodes {
              id name email
              shippingAddress {
                firstName lastName company address1 address2
                city provinceCode zip countryCodeV2 phone
              }
              lineItems(first: 250) {
                nodes {
                  sku name quantity unfulfilledQuantity
                  originalUnitPriceSet { shopMoney { amount } }
                  variant {
                    id barcode
                    inventoryItem {
                      measurement { weight { unit value } }
                    }
                  }
                }
              }
              fulfillmentOrders(first: 10) {
                nodes { id status }
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

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connector = ShopifyConnector::fromConfig();
    }

    public function getSourceName(): string
    {
        return $this->config['config_key'] ?? 'shopify';
    }

    public function validateConfiguration(): void
    {
        if (empty(app(SettingsService::class)->get('shopify.shop_domain', config('services.shopify.shop_domain')))) {
            throw new InvalidArgumentException('Shopify shop domain is not configured (SHOPIFY_SHOP_DOMAIN).');
        }

        if (empty(app(SettingsService::class)->get('shopify.client_id', config('services.shopify.client_id')))) {
            throw new InvalidArgumentException('Shopify client ID is not configured (SHOPIFY_CLIENT_ID).');
        }

        if (empty(app(SettingsService::class)->get('shopify.client_secret', config('services.shopify.client_secret')))) {
            throw new InvalidArgumentException('Shopify client secret is not configured (SHOPIFY_CLIENT_SECRET).');
        }

        if (empty($this->config['channel_name'])) {
            throw new InvalidArgumentException('Shopify channel name is not configured.');
        }
    }

    public function fetchShipments(): Collection
    {
        $this->orderCache = [];
        $allOrders = [];
        $cursor = null;

        do {
            $response = $this->connector->send(
                new GraphQL(self::ORDERS_QUERY, array_filter(['cursor' => $cursor]))
            );

            $json = $response->json();

            if (! empty($json['errors'])) {
                throw new RuntimeException(
                    'Shopify GraphQL error: '.json_encode($json['errors'])
                );
            }

            $ordersData = $json['data']['orders'] ?? [];
            $nodes = $ordersData['nodes'] ?? [];
            $pageInfo = $ordersData['pageInfo'] ?? [];

            foreach ($nodes as $order) {
                $mapped = $this->mapOrderToShipment($order);
                $allOrders[] = $mapped;

                // Cache full order for fetchShipmentItems
                $this->orderCache[$mapped['source_record_id']] = $order;
            }

            $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($cursor !== null);

        return collect($allOrders);
    }

    public function fetchShipmentItems(string $sourceRecordId): Collection
    {
        $order = $this->orderCache[$sourceRecordId] ?? null;

        if (! $order) {
            return collect();
        }

        $lineItems = $order['lineItems']['nodes'] ?? [];

        return collect($lineItems)
            ->filter(fn (array $item) => ($item['unfulfilledQuantity'] ?? 0) > 0)
            ->map(fn (array $item) => $this->mapLineItemToShipmentItem($item))
            ->values();
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

    public function exportPackage(array $data): void
    {
        $this->validateExportConfiguration();

        $fulfillmentOrderId = $data['fulfillment_order_id'] ?? null;

        if (empty($fulfillmentOrderId)) {
            throw new InvalidArgumentException(
                "Cannot export package for shipment {$data['shipment_reference']}: no fulfillment order ID in metadata."
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
            throw new RuntimeException(
                'Shopify GraphQL error: '.json_encode($json['errors'])
            );
        }

        $userErrors = $json['data']['fulfillmentCreate']['userErrors'] ?? [];

        if (! empty($userErrors)) {
            $messages = array_map(
                fn (array $e) => ($e['field'] ?? 'unknown').': '.$e['message'],
                $userErrors
            );
            throw new RuntimeException('Shopify fulfillment error: '.implode('; ', $messages));
        }
    }

    public function validateExportConfiguration(): void
    {
        if (empty(app(SettingsService::class)->get('shopify.shop_domain', config('services.shopify.shop_domain')))
            || empty(app(SettingsService::class)->get('shopify.client_id', config('services.shopify.client_id')))
            || empty(app(SettingsService::class)->get('shopify.client_secret', config('services.shopify.client_secret')))) {
            throw new InvalidArgumentException('Shopify credentials are not configured.');
        }
    }

    private function mapOrderToShipment(array $order): array
    {
        $address = $order['shippingAddress'] ?? [];
        $lineItems = $order['lineItems']['nodes'] ?? [];

        // Sum line item values
        $totalValue = 0;
        foreach ($lineItems as $item) {
            $unitPrice = (float) ($item['originalUnitPriceSet']['shopMoney']['amount'] ?? 0);
            $qty = (int) ($item['unfulfilledQuantity'] ?? $item['quantity'] ?? 0);
            $totalValue += $unitPrice * $qty;
        }

        // Collect fulfillment order IDs (only OPEN or IN_PROGRESS)
        $fulfillmentOrderIds = [];
        foreach ($order['fulfillmentOrders']['nodes'] ?? [] as $fo) {
            if (in_array($fo['status'] ?? '', ['OPEN', 'IN_PROGRESS'], true)) {
                $fulfillmentOrderIds[] = $fo['id'];
            }
        }

        return [
            'source_record_id' => $order['id'] ?? ($order['name'] ?? ''),
            'shipment_reference' => $order['name'] ?? '',
            'first_name' => $address['firstName'] ?? null,
            'last_name' => $address['lastName'] ?? null,
            'company' => $address['company'] ?? null,
            'address1' => $address['address1'] ?? null,
            'address2' => $address['address2'] ?? null,
            'city' => $address['city'] ?? null,
            'state_or_province' => $address['provinceCode'] ?? null,
            'postal_code' => $address['zip'] ?? null,
            'country' => $address['countryCodeV2'] ?? 'US',
            'phone' => $address['phone'] ?? null,
            'email' => $order['email'] ?? null,
            'value' => round($totalValue, 2),
            'channel_id' => $this->config['channel_name'] ?? 'Shopify',
            'shipping_method_id' => $this->config['shipping_method'] ?? null,
            'deliver_by' => null, // Shopify doesn't expose a deliver-by date; commitment_days fallback handles this
            'metadata' => [
                'shopify_order_id' => $order['id'] ?? null,
                'shopify_fulfillment_order_ids' => $fulfillmentOrderIds,
            ],
        ];
    }

    private function mapLineItemToShipmentItem(array $item): array
    {
        $unitPrice = (float) ($item['originalUnitPriceSet']['shopMoney']['amount'] ?? 0);
        $variant = $item['variant'] ?? [];
        $weight = $variant['inventoryItem']['measurement']['weight'] ?? null;

        // Convert weight to pounds (our internal unit)
        $weightLbs = null;
        if ($weight && $weight['value'] > 0) {
            $weightLbs = match ($weight['unit'] ?? '') {
                'POUNDS' => $weight['value'],
                'OUNCES' => $weight['value'] / 16,
                'GRAMS' => $weight['value'] / 453.59237,
                'KILOGRAMS' => $weight['value'] * 2.20462,
                default => $weight['value'],
            };
        }

        // Use SKU if available, otherwise fall back to Shopify variant ID
        $sku = $item['sku'] ?? null;
        if (empty($sku) && ! empty($variant['id'])) {
            // Extract numeric ID from GID (e.g. "gid://shopify/ProductVariant/12345" → "12345")
            $numericId = preg_replace('/.*\//', '', $variant['id']);
            $sku = "SHOPIFY-V-{$numericId}";
        }

        return [
            'sku' => $sku,
            'name' => $item['name'] ?? null,
            'quantity' => (int) ($item['unfulfilledQuantity'] ?? 0),
            'value' => $unitPrice,
            'barcode' => $variant['barcode'] ?? null,
            'weight' => $weightLbs,
        ];
    }
}
