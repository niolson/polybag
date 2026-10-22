<?php

use App\Console\Commands\DemoProvision;
use App\Models\DataSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;

/**
 * `live-store-demo/04`: before a demo tenant is torn down, every store the
 * prospect connected is switched off and its credentials cleared, and our
 * own Amazon Shipping connection, which every demo reuses, is left alone.
 */
beforeEach(function (): void {
    $this->app['env'] = 'demo';
});

it('refuses to run outside Demo', function (): void {
    $this->app['env'] = 'production';
    $shopify = DataSource::factory()->shopify()->create([
        'active' => true,
        'secret_settings' => ['oauth_access_token' => 'shpat_live'],
    ]);

    $this->artisan('demo:disconnect')->assertFailed();

    expect($shopify->fresh()->active)->toBeTrue()
        ->and($shopify->fresh()->secret('oauth_access_token'))->toBe('shpat_live');
});

it('switches off every store the prospect connected and clears all of its secrets', function (): void {
    $shopify = DataSource::factory()->shopify()->create([
        'name' => 'Prospect Shopify',
        'active' => true,
        'import_enabled' => true,
        'settings' => ['shop_domain' => 'prospect.myshopify.com', 'channel_name' => 'Shopify', 'auth_mode' => 'authorization_code'],
        'secret_settings' => ['oauth_access_token' => 'shpat_live', 'client_id' => 'own-app', 'client_secret' => 'own-secret'],
    ]);
    $amazon = DataSource::factory()->amazon()->create([
        'name' => 'Prospect Amazon',
        'active' => true,
        'import_enabled' => true,
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER', 'channel_name' => 'Amazon', 'auth_mode' => 'authorization_code', 'amazon_selling_partner_id' => 'A1SELLER'],
        'secret_settings' => ['refresh_token' => 'Atzr|prospect'],
    ]);

    $this->artisan('demo:disconnect')
        ->expectsOutputToContain('Shopify admin')
        ->expectsOutputToContain('Seller Central')
        ->assertSuccessful();

    foreach ([$shopify->fresh(), $amazon->fresh()] as $connection) {
        expect($connection->active)->toBeFalse()
            ->and($connection->import_enabled)->toBeFalse()
            ->and($connection->secret_settings)->toBeNull()
            ->and($connection->settings)->not->toHaveKey('auth_mode');
    }
});

it('leaves our own Amazon Shipping connection and any Database connection alone', function (): void {
    $ours = DataSource::factory()->amazon()->create([
        'active' => true,
        'import_enabled' => false,
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER', 'auth_mode' => 'manual', DemoProvision::DEMO_CONNECTION_SETTING => true],
        'secret_settings' => ['client_id' => 'sandbox-app', 'client_secret' => 'sandbox-secret', 'refresh_token' => 'Atzr|ours'],
    ]);
    $database = DataSource::factory()->create([
        'source_type' => DatabaseSource::class,
        'active' => true,
        'secret_settings' => ['db_password' => 'demo-db'],
    ]);

    $this->artisan('demo:disconnect')
        ->expectsOutputToContain('our own sandbox connection')
        ->expectsOutputToContain('No store connections to disconnect')
        ->assertSuccessful();

    expect($ours->fresh()->active)->toBeTrue()
        ->and($ours->fresh()->secret('refresh_token'))->toBe('Atzr|ours')
        ->and($database->fresh()->active)->toBeTrue()
        ->and($database->fresh()->secret('db_password'))->toBe('demo-db');
});
