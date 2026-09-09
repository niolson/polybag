<?php

namespace App\Services\ShipmentImport\Sources;

use App\Contracts\DataSourceInterface;
use App\Contracts\ExportDestinationInterface;
use App\Exceptions\PermanentExportException;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Http\Integrations\Shopify\ShopifyConnector;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class ShopifySource implements DataSourceInterface, ExportDestinationInterface
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

    private const RETRYABLE_GRAPHQL_ERROR_CODES = [
        'THROTTLED',
        'INTERNAL_SERVER_ERROR',
        'SERVICE_UNAVAILABLE',
    ];

    private const FULFILLMENT_ORDERS_QUERY = <<<'GRAPHQL'
        query FulfillmentOrders($cursor: String) {
          fulfillmentOrders(first: 20, after: $cursor, includeClosed: false) {
            pageInfo { hasNextPage endCursor }
            nodes {
              id status
              order {
                id name email
                shippingAddress { provinceCode zip }
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
                  variant { id barcode }
                  lineItem { originalUnitPriceSet { shopMoney { amount } } }
                }
              }
            }
          }
        }
        GRAPHQL;

    private const FULFILLMENT_ORDER_ITEMS_QUERY = <<<'GRAPHQL'
        query FulfillmentOrderItems($id: ID!, $cursor: String) {
          fulfillmentOrder(id: $id) {
            lineItems(first: 250, after: $cursor) {
              pageInfo { hasNextPage endCursor }
              nodes {
                id sku productTitle remainingQuantity requiresShipping
                weight { unit value }
                variant { id barcode }
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

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connector = ShopifyConnector::fromSettings($config);
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

        return $this->fetchFulfillmentOrderShipments();
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

    private function fetchFulfillmentOrderShipments(): Collection
    {
        $this->orderCache = [];
        $shipments = [];
        $cursor = null;

        do {
            $response = $this->connector->send(
                new GraphQL(self::FULFILLMENT_ORDERS_QUERY, array_filter(['cursor' => $cursor]))
            );
            $json = $response->json();

            if (! empty($json['errors'])) {
                throw new RuntimeException('Shopify GraphQL error: '.json_encode($json['errors']));
            }

            $data = $json['data']['fulfillmentOrders'] ?? [];
            foreach ($data['nodes'] ?? [] as $fulfillmentOrder) {
                if (! in_array($fulfillmentOrder['status'] ?? '', ['OPEN', 'IN_PROGRESS'], true)) {
                    continue;
                }

                $fulfillmentOrder['lineItems'] = $this->allFulfillmentOrderItems($fulfillmentOrder);
                $mapped = $this->mapFulfillmentOrderToShipment($fulfillmentOrder);
                $shipments[] = $mapped;
                $this->orderCache[$mapped['source_record_id']] = $fulfillmentOrder;
            }

            $pageInfo = $data['pageInfo'] ?? [];
            $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($cursor !== null);

        return collect($shipments);
    }

    /** @return array<int, array<string, mixed>> */
    private function allFulfillmentOrderItems(array $fulfillmentOrder): array
    {
        $connection = $fulfillmentOrder['lineItems'] ?? [];
        $items = $connection['nodes'] ?? [];
        $pageInfo = $connection['pageInfo'] ?? [];
        $cursor = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;

        while ($cursor !== null) {
            $response = $this->connector->send(new GraphQL(self::FULFILLMENT_ORDER_ITEMS_QUERY, [
                'id' => $fulfillmentOrder['id'],
                'cursor' => $cursor,
            ]));
            $json = $response->json();

            if (! empty($json['errors'])) {
                throw new RuntimeException('Shopify GraphQL error: '.json_encode($json['errors']));
            }

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
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function mapFulfillmentOrderLineItem(array $item): array
    {
        $weight = $item['weight'] ?? null;
        $weightLbs = null;
        if ($weight && ($weight['value'] ?? 0) > 0) {
            $weightLbs = match ($weight['unit'] ?? '') {
                'POUNDS' => $weight['value'],
                'OUNCES' => $weight['value'] / 16,
                'GRAMS' => $weight['value'] / 453.59237,
                'KILOGRAMS' => $weight['value'] * 2.20462,
                default => $weight['value'],
            };
        }

        $variant = $item['variant'] ?? [];
        $sku = $item['sku'] ?? null;
        if (empty($sku) && ! empty($variant['id'])) {
            $sku = 'SHOPIFY-V-'.preg_replace('/.*\//', '', $variant['id']);
        }

        return [
            'sku' => $sku,
            'name' => $item['productTitle'] ?? null,
            'quantity' => (int) ($item['remainingQuantity'] ?? 0),
            'value' => (float) data_get($item, 'lineItem.originalUnitPriceSet.shopMoney.amount', 0),
            'barcode' => $variant['barcode'] ?? null,
            'weight' => $weightLbs,
        ];
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
     */
    public function exportPackage(array $data): void
    {
        if (filled($data['_shopify_shipping_label_id'] ?? null)) {
            Log::info('Skipping Shopify fulfillment for a Shopify Shipping label', [
                'shopify_shipping_label_id' => $data['_shopify_shipping_label_id'],
                'package' => $data['_package_reference_id'] ?? null,
            ]);

            return;
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

            if ($allPermanent) {
                return;
            }

            throw new PermanentExportException($message);
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
