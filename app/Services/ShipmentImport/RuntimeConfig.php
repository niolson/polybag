<?php

namespace App\Services\ShipmentImport;

use App\Services\SettingsService;

class RuntimeConfig
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function defaultSource(): string
    {
        return (string) $this->settings->get('import_source', config('shipment-import.default', 'database'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sourceConfig(string $sourceName): ?array
    {
        $config = config("shipment-import.sources.{$sourceName}");

        if (! is_array($config)) {
            return null;
        }

        return match ($sourceName) {
            'database' => $this->resolveDatabaseConfig($config),
            'shopify' => $this->resolveShopifyConfig($config),
            'amazon' => $this->resolveAmazonConfig($config),
            default => $config,
        };
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function exportChannelMap(): array
    {
        /** @var array<string, array<int, string>> $map */
        $map = config('shipment-import.export_channel_map', []);

        unset($map['Shopify'], $map['Amazon']);

        foreach (['shopify', 'amazon'] as $sourceName) {
            $sourceConfig = $this->sourceConfig($sourceName);
            $channelName = is_array($sourceConfig) ? ($sourceConfig['channel_name'] ?? null) : null;
            $exportEnabled = is_array($sourceConfig) ? (bool) ($sourceConfig['export']['enabled'] ?? false) : false;

            if (blank($channelName) || ! $exportEnabled) {
                continue;
            }

            $map[$channelName] = array_values(array_unique([
                ...($map[$channelName] ?? []),
                $sourceName,
            ]));
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function resolveShopifyConfig(array $config): array
    {
        $config['enabled'] = (bool) $this->settings->get('shopify.import_enabled', $config['enabled'] ?? false);
        $config['channel_name'] = (string) $this->settings->get('shopify.channel_name', $config['channel_name'] ?? 'Shopify');
        $config['shipping_method'] = $this->settings->get('shopify.shipping_method', $config['shipping_method'] ?? null);
        $config['notify_customer'] = (bool) $this->settings->get('shopify.notify_customer', $config['notify_customer'] ?? false);
        $config['export']['enabled'] = (bool) $this->settings->get('shopify.export_enabled', $config['export']['enabled'] ?? false);

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function resolveDatabaseConfig(array $config): array
    {
        $shipmentsQuery = $this->settings->get('import.shipments_query');
        $shipmentItemsQuery = $this->settings->get('import.shipment_items_query');
        $exportQuery = $this->settings->get('import.export_query');

        $config['shipments_query'] = filled($shipmentsQuery) ? (string) $shipmentsQuery : null;
        $config['shipment_items_query'] = filled($shipmentItemsQuery) ? (string) $shipmentItemsQuery : null;
        $config['export']['query'] = filled($exportQuery) ? (string) $exportQuery : null;

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function resolveAmazonConfig(array $config): array
    {
        $config['enabled'] = (bool) $this->settings->get('amazon.import_enabled', $config['enabled'] ?? false);
        $config['channel_name'] = (string) $this->settings->get('amazon.channel_name', $config['channel_name'] ?? 'Amazon');
        $config['shipping_method'] = $this->settings->get('amazon.shipping_method', $config['shipping_method'] ?? null);
        $config['lookback_days'] = (int) $this->settings->get('amazon.lookback_days', $config['lookback_days'] ?? 30);
        $config['export']['enabled'] = (bool) $this->settings->get('amazon.export_enabled', $config['export']['enabled'] ?? false);

        return $config;
    }
}
