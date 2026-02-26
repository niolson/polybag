<?php

namespace App\Services\ShipmentImport\Sources;

use App\Contracts\ExportDestinationInterface;
use App\Contracts\ImportSourceInterface;
use App\Http\Integrations\Amazon\AmazonSpApiConnector;
use App\Http\Integrations\Amazon\Requests\ConfirmShipment;
use App\Http\Integrations\Amazon\Requests\SearchOrders;
use App\Services\SettingsService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class AmazonSource implements ExportDestinationInterface, ImportSourceInterface
{
    private array $config;

    private AmazonSpApiConnector $connector;

    /** @var array<string, array> Cached order data keyed by shipment reference */
    private array $orderCache = [];

    private const CARRIER_MAP = [
        'USPS' => 'USPS',
        'FedEx' => 'FedEx',
        'UPS' => 'UPS',
        'DHL' => 'DHL',
    ];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connector = AmazonSpApiConnector::fromConfig();
    }

    public function getSourceName(): string
    {
        return 'amazon';
    }

    public function validateConfiguration(): void
    {
        if (empty(app(SettingsService::class)->get('amazon.client_id', config('services.amazon.client_id')))) {
            throw new InvalidArgumentException('Amazon SP-API client ID is not configured (AMAZON_SP_API_CLIENT_ID).');
        }

        if (empty(app(SettingsService::class)->get('amazon.client_secret', config('services.amazon.client_secret')))) {
            throw new InvalidArgumentException('Amazon SP-API client secret is not configured (AMAZON_SP_API_CLIENT_SECRET).');
        }

        if (empty(app(SettingsService::class)->get('amazon.refresh_token', config('services.amazon.refresh_token')))) {
            throw new InvalidArgumentException('Amazon SP-API refresh token is not configured (AMAZON_SP_API_REFRESH_TOKEN).');
        }

        if (empty(app(SettingsService::class)->get('amazon.marketplace_id', config('services.amazon.marketplace_id')))) {
            throw new InvalidArgumentException('Amazon SP-API marketplace ID is not configured (AMAZON_SP_API_MARKETPLACE_ID).');
        }

        if (empty($this->config['channel_name'])) {
            throw new InvalidArgumentException('Amazon channel name is not configured.');
        }
    }

    public function fetchShipments(): Collection
    {
        $this->orderCache = [];
        $allOrders = [];
        $paginationToken = null;
        $sandbox = (bool) app(SettingsService::class)->get('sandbox_mode', false);
        $marketplaceId = app(SettingsService::class)->get('amazon.marketplace_id', config('services.amazon.marketplace_id', 'ATVPDKIKX0DER'));
        $lookbackDays = $this->config['lookback_days'] ?? 30;
        $lastUpdatedAfter = now()->subDays($lookbackDays)->toIso8601String();

        do {
            if ($sandbox) {
                // v2026-01-01 sandbox test cases only exist for JP/UK/BR marketplaces.
                // Test Case 1: Japan marketplace with all includedData values.
                $query = [
                    'marketplaceIds' => 'A1VC38T7YXB528',
                    'createdAfter' => '2024-12-25T00:00:00Z',
                    'includedData' => 'BUYER,RECIPIENT,PROCEEDS,EXPENSE,PROMOTION,CANCELLATION,FULFILLMENT,PACKAGES',
                ];
            } elseif ($paginationToken) {
                // When paginating, only paginationToken and marketplaceIds are allowed
                $query = [
                    'marketplaceIds' => $marketplaceId,
                    'paginationToken' => $paginationToken,
                    'includedData' => 'RECIPIENT,BUYER,PROCEEDS,FULFILLMENT',
                ];
            } else {
                $query = [
                    'marketplaceIds' => $marketplaceId,
                    'fulfillmentStatuses' => 'UNSHIPPED,PARTIALLY_SHIPPED',
                    'lastUpdatedAfter' => $lastUpdatedAfter,
                    'maxResultsPerPage' => 100,
                    'includedData' => 'RECIPIENT,BUYER,PROCEEDS,FULFILLMENT',
                ];
            }

            $response = $this->connector->send(new SearchOrders($query));
            $json = $response->json();

            $orders = $json['orders'] ?? [];
            $paginationToken = $sandbox ? null : ($json['pagination']['nextToken'] ?? null);

            foreach ($orders as $order) {
                $mapped = $this->mapOrderToShipment($order);
                $allOrders[] = $mapped;

                $this->orderCache[$mapped['shipment_reference']] = $order;
            }
        } while ($paginationToken !== null);

        return collect($allOrders);
    }

    public function fetchShipmentItems(string $shipmentReference): Collection
    {
        $order = $this->orderCache[$shipmentReference] ?? null;

        if (! $order) {
            return collect();
        }

        $items = $order['orderItems'] ?? [];

        return collect($items)
            ->map(fn (array $item) => $this->mapOrderItemToShipmentItem($item))
            ->filter(fn (array $item) => $item['quantity'] > 0)
            ->values();
    }

    public function getFieldMapping(): array
    {
        return [];
    }

    public function markExported(string $shipmentReference): void
    {
        // No-op: Amazon tracks fulfillment status natively.
        // Fulfilled orders won't match the Unshipped filter on next import.
    }

    public function getDestinationName(): string
    {
        return 'amazon';
    }

    public function exportPackage(array $data): void
    {
        $this->validateExportConfiguration();

        $amazonOrderId = $data['amazon_order_id'] ?? null;

        if (empty($amazonOrderId)) {
            throw new InvalidArgumentException(
                "Cannot export package for shipment {$data['shipment_reference']}: no Amazon order ID in metadata."
            );
        }

        $sandbox = (bool) app(SettingsService::class)->get('sandbox_mode', false);
        $carrierCode = self::CARRIER_MAP[$data['carrier'] ?? ''] ?? ($data['carrier'] ?? null);

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
            $orderId = $amazonOrderId;
            $body = [
                'marketplaceId' => app(SettingsService::class)->get('amazon.marketplace_id', config('services.amazon.marketplace_id', 'ATVPDKIKX0DER')),
                'packageDetail' => [
                    'packageReferenceId' => '1',
                    'carrierCode' => $carrierCode,
                    'trackingNumber' => $data['tracking_number'] ?? null,
                    'shipDate' => now()->toIso8601String(),
                    'orderItems' => $data['_order_items'] ?? [],
                ],
            ];
        }

        $response = $this->connector->send(
            new ConfirmShipment($orderId, $body)
        );

        if (! $response->successful()) {
            $json = $response->json();
            $errors = $json['errors'] ?? [];
            $messages = array_map(
                fn (array $e) => ($e['code'] ?? 'unknown').': '.($e['message'] ?? ''),
                $errors
            );
            throw new RuntimeException('Amazon shipment confirmation error: '.implode('; ', $messages));
        }
    }

    public function validateExportConfiguration(): void
    {
        if (empty(app(SettingsService::class)->get('amazon.client_id', config('services.amazon.client_id')))
            || empty(app(SettingsService::class)->get('amazon.client_secret', config('services.amazon.client_secret')))
            || empty(app(SettingsService::class)->get('amazon.refresh_token', config('services.amazon.refresh_token')))) {
            throw new InvalidArgumentException('Amazon SP-API credentials are not configured.');
        }
    }

    private function mapOrderToShipment(array $order): array
    {
        $address = $order['recipient']['deliveryAddress'] ?? [];
        $items = $order['orderItems'] ?? [];

        // Sum line item values for unfulfilled items using proceeds breakdowns
        $sandbox = (bool) app(SettingsService::class)->get('sandbox_mode', false);
        $totalValue = 0;
        foreach ($items as $item) {
            $qtyOrdered = (int) ($item['quantityOrdered'] ?? 0);
            $qty = $sandbox
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

        return [
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
            'email' => $order['buyer']['buyerEmail'] ?? null,
            'value' => round($totalValue, 2),
            'channel_id' => $this->config['channel_name'] ?? 'Amazon',
            'shipping_method_id' => $this->config['shipping_method'] ?? null,
            'deliver_by' => null,
            'metadata' => [
                'amazon_order_id' => $order['orderId'] ?? null,
            ],
        ];
    }

    private function mapOrderItemToShipmentItem(array $item): array
    {
        $qtyOrdered = (int) ($item['quantityOrdered'] ?? 0);
        $sandbox = (bool) app(SettingsService::class)->get('sandbox_mode', false);
        $qtyRemaining = $sandbox
            ? $qtyOrdered
            : max(0, $qtyOrdered - (int) ($item['fulfillment']['quantityFulfilled'] ?? 0));

        $itemTotal = $this->sumItemProceeds($item);
        $unitPrice = $qtyOrdered > 0 ? $itemTotal / $qtyOrdered : 0;

        return [
            'sku' => $item['product']['sellerSku'] ?? null,
            'name' => $item['product']['title'] ?? null,
            'quantity' => $qtyRemaining,
            'value' => round($unitPrice, 2),
            'barcode' => null,
            'weight' => null,
        ];
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
