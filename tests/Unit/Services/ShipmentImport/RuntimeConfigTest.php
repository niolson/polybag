<?php

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\ShipmentImport\RuntimeConfig;

beforeEach(function (): void {
    app(SettingsService::class)->clearCache();
});

it('uses the saved default import source', function (): void {
    Setting::updateOrCreate(
        ['key' => 'import_source'],
        ['value' => 'shopify', 'type' => 'string', 'group' => 'general'],
    );
    app(SettingsService::class)->clearCache();

    expect(app(RuntimeConfig::class)->defaultSource())->toBe('shopify');
});

it('overlays saved shopify runtime settings onto the base config', function (): void {
    Setting::updateOrCreate(
        ['key' => 'shopify.import_enabled'],
        ['value' => '1', 'type' => 'boolean', 'group' => 'shopify'],
    );
    Setting::updateOrCreate(
        ['key' => 'shopify.export_enabled'],
        ['value' => '1', 'type' => 'boolean', 'group' => 'shopify'],
    );
    Setting::updateOrCreate(
        ['key' => 'shopify.channel_name'],
        ['value' => 'Storefront', 'type' => 'string', 'group' => 'shopify'],
    );
    Setting::updateOrCreate(
        ['key' => 'shopify.shipping_method'],
        ['value' => '42', 'type' => 'string', 'group' => 'shopify'],
    );
    Setting::updateOrCreate(
        ['key' => 'shopify.notify_customer'],
        ['value' => '1', 'type' => 'boolean', 'group' => 'shopify'],
    );
    app(SettingsService::class)->clearCache();

    $config = app(RuntimeConfig::class)->sourceConfig('shopify');

    expect($config)->not->toBeNull()
        ->and($config['enabled'])->toBeTrue()
        ->and($config['channel_name'])->toBe('Storefront')
        ->and($config['shipping_method'])->toBe('42')
        ->and($config['notify_customer'])->toBeTrue()
        ->and($config['export']['enabled'])->toBeTrue();
});

it('overlays saved database sql queries onto the base config', function (): void {
    Setting::updateOrCreate(
        ['key' => 'import.shipments_query'],
        ['value' => 'select * from shipments where exported = 0', 'type' => 'string', 'group' => 'import'],
    );
    Setting::updateOrCreate(
        ['key' => 'import.shipment_items_query'],
        ['value' => 'select * from shipment_items where shipment_id = :shipment_reference', 'type' => 'string', 'group' => 'import'],
    );
    Setting::updateOrCreate(
        ['key' => 'import.export_query'],
        ['value' => 'update orders set tracking_number = :tracking_number where id = :shipment_reference', 'type' => 'string', 'group' => 'import'],
    );
    app(SettingsService::class)->clearCache();

    $config = app(RuntimeConfig::class)->sourceConfig('database');

    expect($config)->not->toBeNull()
        ->and($config['shipments_query'])->toBe('select * from shipments where exported = 0')
        ->and($config['shipment_items_query'])->toBe('select * from shipment_items where shipment_id = :shipment_reference')
        ->and($config['export']['query'])->toBe('update orders set tracking_number = :tracking_number where id = :shipment_reference');
});

it('uses saved export channel names only for enabled export destinations', function (): void {
    Setting::updateOrCreate(
        ['key' => 'shopify.channel_name'],
        ['value' => 'Storefront', 'type' => 'string', 'group' => 'shopify'],
    );
    Setting::updateOrCreate(
        ['key' => 'shopify.export_enabled'],
        ['value' => '1', 'type' => 'boolean', 'group' => 'shopify'],
    );
    Setting::updateOrCreate(
        ['key' => 'amazon.channel_name'],
        ['value' => 'Marketplace', 'type' => 'string', 'group' => 'amazon'],
    );
    Setting::updateOrCreate(
        ['key' => 'amazon.export_enabled'],
        ['value' => '0', 'type' => 'boolean', 'group' => 'amazon'],
    );
    app(SettingsService::class)->clearCache();

    $map = app(RuntimeConfig::class)->exportChannelMap();

    expect($map)->toHaveKey('Storefront')
        ->and($map['Storefront'])->toBe(['shopify'])
        ->and($map)->not->toHaveKey('Marketplace')
        ->and($map)->toHaveKey('*')
        ->and($map['*'])->toBe(['database']);
});
