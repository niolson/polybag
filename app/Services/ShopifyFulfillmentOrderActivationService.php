<?php

namespace App\Services;

use App\Models\DataSource;
use App\Services\ShipmentImport\DataSourceFactory;
use App\Services\ShipmentImport\Sources\ShopifySource;
use DomainException;
use InvalidArgumentException;

class ShopifyFulfillmentOrderActivationService
{
    /**
     * Scopes a fulfillment-order import needs. Checked at activation, on every
     * location sync, and when an import starts.
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

    public function __construct(private readonly DataSourceFactory $factory) {}

    public function activate(DataSource $dataSource): void
    {
        if ($dataSource->source_type !== ShopifySource::class) {
            throw new InvalidArgumentException('Only Shopify Data Sources can activate fulfillment-order imports.');
        }

        if ($dataSource->settings['fulfillment_order_import_enabled'] ?? false) {
            return;
        }

        $source = $this->factory->make($dataSource);
        if (! $source instanceof ShopifySource) {
            throw new InvalidArgumentException('The Shopify Data Source could not be initialized.');
        }

        $scopes = $source->fetchAccessScopes();
        $missingScopes = array_values(array_diff(self::REQUIRED_SCOPES, $scopes));
        if ($missingScopes !== []) {
            throw new DomainException('Reconnect Shopify with the required scopes before activation: '.implode(', ', $missingScopes).'.');
        }

        if (! $dataSource->locations()->where('is_active', true)->exists()) {
            throw new DomainException('Synchronize Shopify locations before activation.');
        }

        $unmapped = $dataSource->locations()
            ->where('is_active', true)
            ->whereNull('location_id')
            ->whereNull('ignored_at')
            ->count();
        if ($unmapped > 0) {
            throw new DomainException("Map or ignore all active Shopify locations before activation ({$unmapped} remaining).");
        }

        $settings = $dataSource->settings ?? [];
        $settings['fulfillment_order_import_enabled'] = true;
        $settings['authoritative_shipment_items'] = true;
        $dataSource->settings = $settings;
        $dataSource->save();
    }
}
