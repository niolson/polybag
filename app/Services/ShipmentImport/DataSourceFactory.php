<?php

namespace App\Services\ShipmentImport;

use App\Contracts\DataSourceInterface;
use App\Models\DataSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DataSourceFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public function make(DataSource $dataSource, array $overrides = []): DataSourceInterface
    {
        $driver = $dataSource->source_type;

        if (! $driver || ! class_exists($driver)) {
            throw new InvalidArgumentException(
                "Data source '{$dataSource->name}' has an invalid driver class: {$driver}"
            );
        }

        $config = array_merge(
            $dataSource->settings ?? [],
            $dataSource->secret_settings ?? [],
            $overrides,
        );

        if ($driver === DatabaseSource::class) {
            $config = $this->buildDatabaseConfig($dataSource->id, $config);
        }

        $config['_data_source_id'] = $dataSource->id;

        return new $driver($config);
    }

    /**
     * Register a per-source dynamic DB connection — so multiple database sources
     * can run concurrently without overwriting each other's connection config —
     * and build the DatabaseSource config bound to it.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildDatabaseConfig(int $sourceId, array $settings): array
    {
        $connectionName = 'import_'.$sourceId;

        // A file-based driver has no host to configure, so gating purely on
        // db_host would leave its connection unregistered and unusable.
        $hasConnectionSettings = filled($settings['db_host'] ?? null)
            || ! ImportConnectionConfig::usesHost($settings['db_driver'] ?? 'mysql');

        if ($hasConnectionSettings) {
            config([
                "database.connections.{$connectionName}" => ImportConnectionConfig::build(
                    $settings,
                    $settings['db_password'] ?? null,
                ),
            ]);
            DB::purge($connectionName);
        }

        return self::databaseConfigFor($settings, $connectionName, $sourceId);
    }

    /**
     * Build a fully-structured DatabaseSource config from flat DataSource settings
     * against an already-registered connection. Split out so the query preview in
     * the DataSource form can build the same config — including the field-mapping
     * defaults — for an unsaved source over its own test connection.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function databaseConfigFor(array $settings, string $connectionName, ?int $sourceId = null): array
    {
        return [
            'connection' => $connectionName,
            'data_source_id' => $sourceId,
            'enabled' => true,
            'shipments_table' => $settings['shipments_table'] ?? 'shipments',
            'shipment_items_table' => $settings['shipment_items_table'] ?? 'shipment_items',
            'client_column' => $settings['client_column'] ?? null,
            'shipments_query' => $settings['shipments_query'] ?? null,
            'shipment_items_query' => $settings['shipment_items_query'] ?? null,
            'max_affected_rows' => max(1, (int) ($settings['max_affected_rows'] ?? 1)),
            'shipment_items' => ['enabled' => true],
            'filters' => $settings['filters'] ?? [],
            'mark_exported' => [
                'enabled' => (bool) ($settings['mark_exported_enabled'] ?? false),
                'query' => $settings['mark_exported_query'] ?? null,
            ],
            'export' => [
                'enabled' => (bool) ($settings['export_enabled'] ?? false),
                'query' => $settings['export_query'] ?? null,
                'field_mapping' => [
                    'tracking_number' => 'tracking_number',
                    'weight' => 'weight',
                    'height' => 'height',
                    'width' => 'width',
                    'length' => 'length',
                    'cost' => 'cost',
                    'carrier' => 'carrier',
                    'service' => 'service',
                    'shipment_reference' => 'shipment_reference',
                ],
            ],
            'ssh' => [
                'enabled' => (bool) ($settings['ssh_enabled'] ?? false),
                'host' => $settings['ssh_host'] ?? null,
                'port' => (int) ($settings['ssh_port'] ?? 22),
                'user' => $settings['ssh_user'] ?? null,
                'key' => storage_path('app/private/ssh/id_ed25519'),
                'remote_host' => $settings['ssh_remote_host'] ?? null,
                'remote_port' => $settings['ssh_remote_port'] ?? null,
                'host_key' => $settings['ssh_host_key'] ?? null,
                'known_hosts_file' => storage_path('app/private/ssh/import_known_hosts'),
            ],
            'field_mapping' => $settings['field_mapping'] ?? [
                'shipment' => [
                    'id' => 'shipment_reference',
                    'first_name' => 'first_name',
                    'last_name' => 'last_name',
                    'company' => 'company',
                    'address1' => 'address1',
                    'address2' => 'address2',
                    'city' => 'city',
                    'state' => 'state_or_province',
                    'zip' => 'postal_code',
                    'country' => 'country',
                    'phone' => 'phone',
                    'email' => 'email',
                    'value' => 'value',
                    'shipping_method' => 'shipping_method_id',
                    'channel' => 'channel_id',
                ],
                'shipment_item' => [
                    'sku' => 'sku',
                    'name' => 'name',
                    'description' => 'description',
                    'barcode' => 'barcode',
                    'quantity' => 'quantity',
                    'weight' => 'weight',
                    'value' => 'value',
                    'transparency' => 'transparency',
                ],
            ],
        ];
    }
}
