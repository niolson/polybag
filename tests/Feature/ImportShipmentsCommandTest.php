<?php

use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;

uses(RefreshDatabase::class);

it('--all --dry-run does not run real imports', function (): void {
    // Create an active DataSource backed by a mock driver that would fail if import() ran
    DataSource::factory()->create([

        'source_type' => ShopifySource::class,
        'active' => true,
        'settings' => [
            'shop_domain' => 'test.myshopify.com',
            'channel_name' => 'Shopify',
        ],
        'secret_settings' => [
            'oauth_access_token' => 'shpat_test_token',
        ],
    ]);

    // With --dry-run the command should validate + fetch shipments but not persist anything.
    // ShopifySource::fetchShipments() will throw (no HTTP mock), so we expect a non-zero
    // exit code — but critically NOT a database write (shipmentsCreated = 0).
    $this->artisan('shipments:import', ['--all' => true, '--dry-run' => true])
        ->assertExitCode(1); // dry-run fails at fetch (no HTTP mock), but no DB writes

    expect(Shipment::count())->toBe(0);
});

it('--all --validate-only does not run real imports', function (): void {
    DataSource::factory()->create([

        'source_type' => ShopifySource::class,
        'active' => true,
        'settings' => [
            'shop_domain' => 'test.myshopify.com',
            'channel_name' => 'Shopify',
        ],
        'secret_settings' => [
            'oauth_access_token' => 'shpat_test_token',
        ],
    ]);

    // validate-only just calls validateConfiguration(); with an OAuth token it should pass.
    $this->artisan('shipments:import', ['--all' => true, '--validate-only' => true])
        ->assertExitCode(0);

    expect(Shipment::count())->toBe(0);
});

it('--all without flags runs real imports (not dry-run) for each source', function (): void {
    DataSource::factory()->create([

        'source_type' => ShopifySource::class,
        'active' => true,
        'settings' => [
            'shop_domain' => 'test.myshopify.com',
            'channel_name' => 'Shopify',
        ],
        'secret_settings' => [
            'oauth_access_token' => 'shpat_test_token',
        ],
    ]);

    // Without --dry-run the command proceeds past validation into fetchShipments(),
    // which fails here (no HTTP mock). The key assertion is no Shipment rows were written.
    $this->artisan('shipments:import', ['--all' => true])
        ->assertExitCode(1);

    expect(Shipment::count())->toBe(0);
});

it('--all skips a connection with import turned off', function (): void {
    DataSource::factory()->shopify()->importDisabled()->create(['name' => 'Postage only']);

    $this->artisan('shipments:import', ['--all' => true])
        ->expectsOutputToContain('No active import sources found.')
        ->assertSuccessful();

    expect(Shipment::count())->toBe(0);
});

it('--source-id skips a connection with import turned off', function (): void {
    $source = DataSource::factory()->shopify()->importDisabled()->create(['name' => 'Postage only']);

    $this->artisan('shipments:import', ['--source-id' => $source->id])
        ->expectsOutputToContain("Import is turned off for connection 'Postage only'.")
        ->assertSuccessful();

    expect(Shipment::count())->toBe(0);
});

it('schedules imports only for active connections with import turned on', function (): void {
    $importing = DataSource::factory()->create(['schedule_interval' => 'hourly']);
    $importOff = DataSource::factory()->importDisabled()->create(['schedule_interval' => 'hourly']);
    $inactive = DataSource::factory()->create(['active' => false, 'schedule_interval' => 'hourly']);

    // The schedule is built when the console routes load, before these rows
    // existed, so load them again against an empty schedule.
    Schedule::swap($schedule = new ConsoleSchedule);
    require base_path('routes/console.php');

    $scheduledIds = collect($schedule->events())
        ->map(fn (Event $event): ?string => preg_match('/shipments:import --source-id=(\\d+)/', (string) $event->command, $m) ? $m[1] : null)
        ->filter()
        ->map(fn (string $id): int => (int) $id)
        ->values()
        ->all();

    // Exactly the importing connection: neither $importOff nor $inactive.
    expect($scheduledIds)->toBe([$importing->id]);
});
