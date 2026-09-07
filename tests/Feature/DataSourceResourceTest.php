<?php

use App\Enums\AmazonMarketplace;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Filament\Resources\DataSources\DataSourceResource;
use App\Filament\Resources\DataSources\Pages\CreateDataSource;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
use App\Filament\Resources\DataSources\Pages\ListDataSources;
use App\Http\Integrations\Amazon\Requests\GetMarketplaceParticipations;
use App\Jobs\RunDataSourceImportJob;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\DataSource;
use App\Models\DataSourceLocation;
use App\Models\Location;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

// ── Access control ────────────────────────────────────────────────────────────

it('blocks non-admin users from accessing import sources', function (): void {
    $user = User::factory()->create(['role' => Role::User]);
    $this->actingAs($user);

    expect(DataSourceResource::canAccess())->toBeFalse();
});

it('allows admin users to access import sources', function (): void {
    $this->actingAs($this->admin);

    expect(DataSourceResource::canAccess())->toBeTrue();
});

// ── Single-client vs multi-client visibility ──────────────────────────────────

it('hides client and global export fields in single-client mode', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'source_type' => DatabaseSource::class,
            'settings.export_enabled' => true,
        ])
        ->assertFormFieldHidden('client_id')
        ->assertFormFieldHidden('settings.client_column')
        ->assertFormFieldHidden('global_export');
});

it('shows client and global export fields in multi-client mode', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'source_type' => DatabaseSource::class,
            'settings.export_enabled' => true,
        ])
        ->assertFormFieldVisible('client_id')
        ->assertFormFieldVisible('settings.client_column')
        ->assertFormFieldVisible('global_export');
});

it('hides client and global export table columns in single-client mode', function (): void {
    $this->actingAs($this->admin);
    DataSource::factory()->create();

    Livewire::test(ListDataSources::class)
        ->assertTableColumnHidden('client.name')
        ->assertTableColumnHidden('global_export');
});

it('shows client and global export table columns in multi-client mode', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $this->actingAs($this->admin);
    DataSource::factory()->create();

    Livewire::test(ListDataSources::class)
        ->assertTableColumnVisible('client.name')
        ->assertTableColumnVisible('global_export');
});

// ── Manual import trigger ─────────────────────────────────────────────────────

it('dispatches an import job from the edit page header action', function (): void {
    Queue::fake();
    $this->actingAs($this->admin);

    $source = DataSource::factory()->create(['active' => true]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->callAction('run_import')
        ->assertNotified('Import queued');

    Queue::assertPushed(RunDataSourceImportJob::class, function (RunDataSourceImportJob $job) use ($source): bool {
        return $job->dataSourceId === $source->id && $job->userId === $this->admin->id;
    });
});

it('disables the run import action for inactive sources', function (): void {
    $this->actingAs($this->admin);

    $source = DataSource::factory()->create(['active' => false]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->assertActionDisabled('run_import');
});

it('queues a bounded historical Amazon import from the edit page', function (): void {
    config(['app.env' => 'local']);
    Queue::fake();
    $this->actingAs($this->admin);

    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'active' => true,
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->assertActionVisible('import_historical_amazon_orders')
        ->callAction('import_historical_amazon_orders', data: [
            'created_after' => '2025-12-01',
            'created_before' => '2025-12-08',
            'max_orders' => 1000,
        ])
        ->assertNotified('Historical Amazon import queued');

    Queue::assertPushed(RunDataSourceImportJob::class, function (RunDataSourceImportJob $job) use ($source): bool {
        expect($job->dataSourceId)->toBe($source->id)
            ->and($job->sourceOverrides)->toBe([
                '_historical_import' => true,
                '_historical_created_after' => '2025-12-01T00:00:00+00:00',
                '_historical_created_before' => '2025-12-08T23:59:59+00:00',
                '_historical_max_orders' => 1000,
            ]);

        return true;
    });
});

it('queues a historical Amazon import across a date range longer than thirty one days', function (): void {
    config(['app.env' => 'local']);
    Queue::fake();
    $this->actingAs($this->admin);

    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'active' => true,
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->callAction('import_historical_amazon_orders', data: [
            'created_after' => '2025-01-01',
            'created_before' => '2025-12-01',
            'max_orders' => 1000,
        ])
        ->assertNotified('Historical Amazon import queued');

    Queue::assertPushed(RunDataSourceImportJob::class, function (RunDataSourceImportJob $job): bool {
        return $job->sourceOverrides['_historical_created_after'] === '2025-01-01T00:00:00+00:00'
            && $job->sourceOverrides['_historical_created_before'] === '2025-12-01T23:59:59+00:00'
            && $job->sourceOverrides['_historical_max_orders'] === 1000;
    });
});

it('shows an inline error when the historical Amazon end date precedes the start date', function (): void {
    config(['app.env' => 'local']);
    Queue::fake();
    $this->actingAs($this->admin);

    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'active' => true,
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->callAction('import_historical_amazon_orders', data: [
            'created_after' => '2025-12-01',
            'created_before' => '2025-01-01',
            'max_orders' => 1000,
        ])
        ->assertHasActionErrors(['created_before']);

    Queue::assertNothingPushed();
});

it('disables historical Amazon imports while sandbox mode is enabled', function (): void {
    config(['app.env' => 'local']);
    app(SettingsService::class)->set('sandbox_mode', true);
    $this->actingAs($this->admin);

    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'active' => true,
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->assertActionDisabled('import_historical_amazon_orders');
});

it('hides historical Amazon imports outside the local environment', function (): void {
    config(['app.env' => 'production']);
    $this->actingAs($this->admin);

    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'active' => true,
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->assertActionHidden('import_historical_amazon_orders');
});

it('dispatches an import job from the table row action', function (): void {
    Queue::fake();
    $this->actingAs($this->admin);

    $source = DataSource::factory()->create(['active' => true]);

    Livewire::test(ListDataSources::class)
        ->callAction(TestAction::make('run_import')->table($source))
        ->assertNotified('Import queued');

    Queue::assertPushed(RunDataSourceImportJob::class, fn (RunDataSourceImportJob $job): bool => $job->dataSourceId === $source->id);
});

it('hides the table run import action for inactive sources', function (): void {
    $this->actingAs($this->admin);

    $source = DataSource::factory()->create(['active' => false]);

    Livewire::test(ListDataSources::class)
        ->assertActionHidden(TestAction::make('run_import')->table($source));
});

// ── Database driver form behavior ─────────────────────────────────────────────

it('reveals the mark exported query field when the toggle is enabled', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm(['source_type' => DatabaseSource::class])
        ->assertFormFieldHidden('settings.mark_exported_query')
        ->fillForm(['settings.mark_exported_enabled' => true])
        ->assertFormFieldVisible('settings.mark_exported_query');
});

it('shows the generated ssh public key when database ssh tunneling is enabled', function (): void {
    $pubKeyPath = storage_path('app/private/ssh/id_ed25519.pub');
    $existingPublicKey = File::exists($pubKeyPath) ? File::get($pubKeyPath) : null;

    try {
        File::ensureDirectoryExists(dirname($pubKeyPath));
        File::put($pubKeyPath, 'ssh-ed25519 AAAApolybagpublickey');

        $this->actingAs($this->admin);

        Livewire::test(CreateDataSource::class)
            ->fillForm([
                'source_type' => DatabaseSource::class,
                'settings.ssh_enabled' => true,
            ])
            ->assertFormFieldVisible('ssh_public_key')
            ->assertSchemaStateSet([
                'ssh_public_key' => 'restrict,port-forwarding ssh-ed25519 AAAApolybagpublickey',
            ]);
    } finally {
        if ($existingPublicKey === null) {
            File::delete($pubKeyPath);
        } else {
            File::put($pubKeyPath, $existingPublicKey);
        }
    }
});

it('shows the generated ssh public key on the edit form when ssh tunneling is enabled', function (): void {
    $pubKeyPath = storage_path('app/private/ssh/id_ed25519.pub');
    $existingPublicKey = File::exists($pubKeyPath) ? File::get($pubKeyPath) : null;

    try {
        File::ensureDirectoryExists(dirname($pubKeyPath));
        File::put($pubKeyPath, 'ssh-ed25519 AAAApolybagpublickey');

        $this->actingAs($this->admin);

        $source = DataSource::factory()->create([
            'settings' => ['ssh_enabled' => true],
        ]);

        Livewire::test(EditDataSource::class, ['record' => $source->id])
            ->assertFormFieldVisible('ssh_public_key')
            ->assertSchemaStateSet([
                'ssh_public_key' => 'restrict,port-forwarding ssh-ed25519 AAAApolybagpublickey',
            ]);
    } finally {
        if ($existingPublicKey === null) {
            File::delete($pubKeyPath);
        } else {
            File::put($pubKeyPath, $existingPublicKey);
        }
    }
});

// ── Query preview ─────────────────────────────────────────────────────────────

/**
 * A throwaway sqlite file standing in for a customer database, so the preview can
 * be driven end to end through the form without a live external connection.
 */
function previewSourceDatabase(): string
{
    $path = storage_path('framework/testing/preview_source.sqlite');

    File::ensureDirectoryExists(dirname($path));
    File::put($path, '');

    config(['database.connections.preview_source_db' => ['driver' => 'sqlite', 'database' => $path]]);
    DB::purge('preview_source_db');

    DB::connection('preview_source_db')->statement('CREATE TABLE source_orders (order_no TEXT, ship_to TEXT)');
    DB::connection('preview_source_db')->table('source_orders')->insert([
        ['order_no' => 'A-1', 'ship_to' => 'Ada'],
        ['order_no' => 'A-2', 'ship_to' => 'Grace'],
    ]);

    return $path;
}

/**
 * The preview modal renders on the client, so its content is read off the mounted
 * action rather than out of the component's HTML.
 */
function mountedModalHtml(Testable $component): string
{
    $page = $component->instance();

    if (! $page instanceof EditDataSource) {
        throw new RuntimeException('Expected the data source edit page.');
    }

    $content = $page->getMountedAction()?->getModalContent();

    return match (true) {
        $content instanceof View => $content->render(),
        $content instanceof Htmlable => $content->toHtml(),
        default => '',
    };
}

function previewDataSource(array $settings): DataSource
{
    return DataSource::factory()->create([
        'source_type' => DatabaseSource::class,
        'settings' => array_merge([
            'db_driver' => 'sqlite',
            'db_database' => previewSourceDatabase(),
        ], $settings),
    ]);
}

it('previews sample rows and the mapping for the configured queries', function (): void {
    $this->actingAs($this->admin);

    $source = previewDataSource([
        'shipments_query' => 'SELECT * FROM source_orders',
        'field_mapping' => [
            'shipment' => ['order_no' => 'shipment_reference', 'ship_to' => 'first_name'],
        ],
    ]);

    $html = mountedModalHtml(
        Livewire::test(EditDataSource::class, ['record' => $source->id])
            ->mountAction(TestAction::make('test_queries')->schemaComponent('database_query')),
    );

    // Raw source columns and their values…
    expect($html)->toContain('order_no')
        ->toContain('Ada')
        ->toContain('Grace')
        // …alongside what the field mapping resolves them to.
        ->toContain('shipment_reference')
        ->toContain('first_name');

    File::delete($source->settings['db_database']);
});

it('reports a failing preview query against its label', function (): void {
    $this->actingAs($this->admin);

    $source = previewDataSource(['shipments_query' => 'SELECT * FROM no_such_table']);

    $html = mountedModalHtml(
        Livewire::test(EditDataSource::class, ['record' => $source->id])
            ->mountAction(TestAction::make('test_queries')->schemaComponent('database_query')),
    );

    expect($html)->toContain('Shipments query failed')
        ->toContain('no_such_table');

    File::delete($source->settings['db_database']);
});

it('parse-checks the write queries without executing them', function (): void {
    $this->actingAs($this->admin);

    $source = previewDataSource([
        'shipments_query' => 'SELECT * FROM source_orders',
        'mark_exported_enabled' => true,
        'mark_exported_query' => "UPDATE source_orders SET ship_to = 'exported'",
    ]);

    $html = mountedModalHtml(
        Livewire::test(EditDataSource::class, ['record' => $source->id])
            ->mountAction(TestAction::make('test_queries')->schemaComponent('database_query')),
    );

    expect($html)->toContain('Mark exported query: valid');

    // Parse-checked only — the write never ran against the source database.
    expect(DB::connection('preview_source_db')->table('source_orders')->where('ship_to', 'Ada')->count())->toBe(1);

    File::delete($source->settings['db_database']);
});

it('runs each previewed query once per click, not once per re-render', function (): void {
    $this->actingAs($this->admin);

    $source = previewDataSource(['shipments_query' => 'SELECT * FROM source_orders']);

    $previewsRun = fn (): int => AuditLog::where('action', AuditAction::DataSourceQueryExecuted)->count();

    $component = Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->mountAction(TestAction::make('test_queries')->schemaComponent('database_query'));

    expect($previewsRun())->toBe(1);

    // Every Livewire round trip re-renders the open modal. The rows must come
    // from the preview the click produced, not from running the operator's SQL
    // against the customer database again.
    $component->call('$refresh')->set('data.name', 'Renamed while previewing');

    expect($previewsRun())->toBe(1)
        ->and(mountedModalHtml($component))->toContain('Ada');

    // A fresh click is a fresh look at the source, though.
    $component->unmountAction()
        ->mountAction(TestAction::make('test_queries')->schemaComponent('database_query'));

    expect($previewsRun())->toBe(2);

    File::delete($source->settings['db_database']);
});

it('asks for a fresh click once a long-open preview has expired', function (): void {
    $this->actingAs($this->admin);

    $source = previewDataSource(['shipments_query' => 'SELECT * FROM source_orders']);

    $component = Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->mountAction(TestAction::make('test_queries')->schemaComponent('database_query'));

    $this->travel(6)->minutes();

    // Rebuilding here would put every later render of this modal back on the
    // customer database — the deferred form of the defect the mount-time build
    // exists to prevent.
    expect(mountedModalHtml($component->call('$refresh')))->toContain('This preview has expired')
        ->and(AuditLog::where('action', AuditAction::DataSourceQueryExecuted)->count())->toBe(1);

    File::delete($source->settings['db_database']);
});

it('can test the database connection before the source is created', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'New DB Source',
            'source_type' => DatabaseSource::class,
            'settings.db_driver' => 'sqlite',
            'settings.db_database' => ':memory:',
        ])
        ->callAction(TestAction::make('test_db_connection')->schemaComponent('database_connection'))
        ->assertNotified('Connection successful');
});

it('reports a failed connection test before the source is created', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'New DB Source',
            'source_type' => DatabaseSource::class,
            'settings.db_driver' => 'sqlite',
            'settings.db_database' => '/nonexistent/path/db.sqlite',
        ])
        ->callAction(TestAction::make('test_db_connection')->schemaComponent('database_connection'))
        ->assertNotified('Connection failed');
});

it('fails a connection test against an unreachable host quickly instead of hanging', function (): void {
    $this->actingAs($this->admin);

    $start = microtime(true);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'Unreachable DB Source',
            'source_type' => DatabaseSource::class,
            'settings.db_driver' => 'mysql',
            'settings.db_host' => '1234',
            'settings.db_port' => '1234',
            'settings.db_database' => '1234',
            'settings.db_username' => '1234',
            'settings.db_password' => '1234',
        ])
        ->callAction(TestAction::make('test_db_connection')->schemaComponent('database_connection'))
        ->assertNotified('Connection failed');

    // Regression guard: PDO/mysqlnd's own connect timeout doesn't reliably bound
    // an unreachable host — it's been observed to hang past a minute, long enough
    // for the reverse proxy to give up first with a 504. This must fail fast.
    expect(microtime(true) - $start)->toBeLessThan(15.0);
});

// ── Secret settings encryption ────────────────────────────────────────────────

it('routes secret keys to encrypted secret_settings on create', function (): void {
    $this->actingAs($this->admin);
    $channel = Channel::factory()->create();

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'Shopify Test',
            'source_type' => ShopifySource::class,
            'settings.shop_domain' => 'test.myshopify.com',
            'settings.client_id' => 'secret_client_id',
            'settings.client_secret' => 'secret_client_secret',
            'settings.channel_name' => $channel->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $record = DataSource::where('name', 'Shopify Test')->firstOrFail();

    // Secrets must be in encrypted column, not plain settings
    expect($record->settings)->not->toHaveKey('client_id');
    expect($record->settings)->not->toHaveKey('client_secret');

    expect($record->secret('client_id'))->toBe('secret_client_id');
    expect($record->secret('client_secret'))->toBe('secret_client_secret');
    // Fulfillment-order import is a guarded, one-way activation; creating a
    // source must not switch it on before its scopes and locations are checked.
    expect($record->settings)->not->toHaveKey('fulfillment_order_import_enabled')
        ->and($record->settings)->not->toHaveKey('authoritative_shipment_items');
});

it('leaves fulfillment-order activation available on a newly created Shopify source', function (): void {
    $this->actingAs($this->admin);

    // Enabling the flag at creation used to hide this action permanently, so the
    // scope and location checks in ShopifyFulfillmentOrderActivationService could
    // never run and a source with insufficient Shopify scopes imported nothing
    // while reporting no error.
    $source = DataSource::factory()->shopify()->create();

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->assertActionVisible('activate_fulfillment_order_import');
});

it('shows synchronized Shopify locations in a fixed mapping repeater', function (): void {
    $this->actingAs($this->admin);
    $source = DataSource::factory()->shopify()->create();
    DataSourceLocation::factory()->create([
        'data_source_id' => $source,
        'name' => 'Main Warehouse',
        'location_id' => Location::factory()->create(),
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->assertFormFieldVisible('locations');
});

it('synchronizes Shopify locations from the edit action', function (): void {
    $this->actingAs($this->admin);
    $source = createShopifyDataSource(secrets: ['oauth_access_token' => 'shpat_test']);
    Saloon::fake([
        shopifyAccessScopesResponse(),
        MockResponse::make([
            'data' => ['locations' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [[
                    'id' => 'gid://shopify/Location/1',
                    'name' => 'Main Warehouse',
                    'isActive' => true,
                    'address' => ['city' => 'Seattle', 'countryCode' => 'US'],
                ]],
            ]],
        ]),
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->callAction('sync_shopify_locations')
        ->assertNotified('Shopify locations synchronized');

    expect($source->locations()->first()->name)->toBe('Main Warehouse');
});

it('reports packed shipments with null locations when a Shopify mapping exists', function (): void {
    $this->actingAs($this->admin);
    $channel = Channel::factory()->create();
    $source = createShopifyDataSource([
        'channel_name' => $channel->id,
    ], ['oauth_access_token' => 'shpat_test']);
    $sourceLocation = DataSourceLocation::factory()->create([
        'data_source_id' => $source,
        'location_id' => Location::getDefault(),
    ]);
    $shipment = Shipment::factory()->create([
        'data_source_id' => $source,
        'data_source_location_id' => $sourceLocation,
        'location_id' => null,
    ]);
    Package::factory()->create(['shipment_id' => $shipment]);

    $preservedCount = Shipment::query()
        ->join('data_source_locations', 'data_source_locations.id', '=', 'shipments.data_source_location_id')
        ->where('shipments.data_source_id', $source->id)
        ->whereNotNull('data_source_locations.location_id')
        ->where(fn ($query) => $query->whereNull('shipments.location_id')
            ->orWhereColumn('shipments.location_id', '!=', 'data_source_locations.location_id'))
        ->whereHas('packages')
        ->count();

    expect($preservedCount)->toBe(1);

    $component = Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($sourceLocation->refresh()->location_id)->toBe(Location::getDefault()->id)
        ->and($shipment->refresh()->location_id)->toBeNull();

    $component->assertNotified('Packed shipments preserved');
});

it('routes db_password to secret_settings on create', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'DB Source',
            'source_type' => DatabaseSource::class,
            'settings.db_host' => 'localhost',
            'settings.db_database' => 'orders',
            'settings.db_username' => 'reader',
            'settings.db_password' => 'supersecret',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $record = DataSource::where('name', 'DB Source')->firstOrFail();

    expect($record->settings)->not->toHaveKey('db_password');
    expect($record->secret('db_password'))->toBe('supersecret');
});

it('preserves existing secrets when a blank password is submitted on edit', function (): void {
    $this->actingAs($this->admin);
    $channel = Channel::factory()->create();

    $source = DataSource::factory()->shopify()->create([
        'secret_settings' => ['client_secret' => 'original_secret'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->fillForm([
            'name' => 'Updated Name',
            'settings.shop_domain' => 'test.myshopify.com',
            'settings.client_secret' => null,
            'settings.channel_name' => $channel->id,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $source->refresh();
    expect($source->secret('client_secret'))->toBe('original_secret');
});

it('replaces a secret when a new value is submitted on edit', function (): void {
    $this->actingAs($this->admin);
    $channel = Channel::factory()->create();

    $source = DataSource::factory()->shopify()->create([
        'secret_settings' => ['client_secret' => 'old_secret'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->fillForm([
            'name' => 'Updated Name',
            'settings.shop_domain' => 'test.myshopify.com',
            'settings.client_secret' => 'new_secret',
            'settings.channel_name' => $channel->id,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $source->refresh();
    expect($source->secret('client_secret'))->toBe('new_secret');
});

// ── ShopifySource validation ──────────────────────────────────────────────────

it('validates when oauth_access_token is present even without tenant credentials', function (): void {
    $source = new ShopifySource([
        'shop_domain' => 'test.myshopify.com',
        'oauth_access_token' => 'shpat_oauth_token',
        'channel_name' => 'Shopify',
    ]);

    // Should not throw — oauth token satisfies the credentials requirement
    $source->validateConfiguration();
    expect(true)->toBeTrue();
});

it('validates when per-source client_id and client_secret are both present', function (): void {
    $source = new ShopifySource([
        'shop_domain' => 'test.myshopify.com',
        'client_id' => 'per_source_id',
        'client_secret' => 'per_source_secret',
        'channel_name' => 'Shopify',
    ]);

    $source->validateConfiguration();
    expect(true)->toBeTrue();
});

it('fails validation when neither token nor credentials exist', function (): void {
    $source = new ShopifySource([
        'shop_domain' => 'test.myshopify.com',
        'channel_name' => 'Shopify',
    ]);

    expect(fn () => $source->validateConfiguration())->toThrow(InvalidArgumentException::class, 'credentials are not configured');
});

// ── Raw-SQL statement-type validation (issue 07) ──────────────────────────────

it('rejects a destructive mark exported query in the form', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'Bad Source',
            'source_type' => DatabaseSource::class,
            'settings.mark_exported_enabled' => true,
            'settings.mark_exported_query' => 'DELETE FROM shipments WHERE id = :shipment_reference',
        ])
        ->call('create')
        ->assertHasFormErrors(['settings.mark_exported_query']);
});

it('rejects a non-SELECT custom shipments query in the form', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'Bad Source',
            'source_type' => DatabaseSource::class,
            'settings.shipments_query' => 'DROP TABLE shipments',
        ])
        ->call('create')
        ->assertHasFormErrors(['settings.shipments_query']);
});

it('accepts a legitimate UPDATE mark exported query in the form', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'Good Source',
            'source_type' => DatabaseSource::class,
            'settings.mark_exported_enabled' => true,
            'settings.mark_exported_query' => "UPDATE shipments SET exported = 'y' WHERE id = :shipment_reference",
        ])
        ->call('create')
        // A valid UPDATE draws no error on the query field itself (other required
        // DB fields are unrelated to the statement-type rule under test).
        ->assertHasNoFormErrors(['settings.mark_exported_query']);
});

// ── Amazon SP-API requires org-wide MFA (PII access) ──────────────────────────

it('blocks creating an active Amazon data source when MFA is not required', function (): void {
    $this->actingAs($this->admin);
    $channel = Channel::factory()->create();

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'Amazon Import',
            'source_type' => AmazonSource::class,
            'active' => true,
            'settings.marketplace_id' => 'ATVPDKIKX0DER',
            'settings.refresh_token' => 'Atzr|test-refresh-token',
            'settings.channel_name' => $channel->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['active']);

    expect(DataSource::where('source_type', AmazonSource::class)->exists())->toBeFalse();
});

it('allows creating an active Amazon data source once MFA is required', function (): void {
    app(SettingsService::class)->set('require_mfa', true, 'boolean');
    $this->actingAs($this->admin);
    $channel = Channel::factory()->create();

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'Amazon Import',
            'source_type' => AmazonSource::class,
            'active' => true,
            'settings.marketplace_id' => 'ATVPDKIKX0DER',
            'settings.refresh_token' => 'Atzr|test-refresh-token',
            'settings.channel_name' => $channel->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(DataSource::where('source_type', AmazonSource::class)->exists())->toBeTrue();
});

it('allows creating an inactive Amazon data source even when MFA is not required', function (): void {
    $this->actingAs($this->admin);
    $channel = Channel::factory()->create();

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'Amazon Import',
            'source_type' => AmazonSource::class,
            'active' => false,
            'settings.marketplace_id' => 'ATVPDKIKX0DER',
            'settings.refresh_token' => 'Atzr|test-refresh-token',
            'settings.channel_name' => $channel->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(DataSource::where('source_type', AmazonSource::class)->exists())->toBeTrue();
});

it('allows creating an Amazon data source without manual credentials when the broker is configured', function (): void {
    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => 'broker-secret',
        'services.oauth.instance_id' => 'test-instance',
    ]);
    $this->actingAs($this->admin);
    $channel = Channel::factory()->create();

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'Amazon OAuth Import',
            'source_type' => AmazonSource::class,
            'active' => false,
            'settings.marketplace_id' => 'ATVPDKIKX0DER',
            'settings.channel_name' => $channel->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $source = DataSource::where('name', 'Amazon OAuth Import')->firstOrFail();
    expect($source->secret('refresh_token'))->toBeNull();
});

it('shows Amazon OAuth actions on an Amazon data source', function (): void {
    config([
        'services.oauth.broker_url' => 'https://connect.polybag.app',
        'services.oauth.broker_secret' => 'broker-secret',
        'services.oauth.instance_id' => 'test-instance',
    ]);
    $this->actingAs($this->admin);
    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => ['auth_mode' => 'authorization_code'],
        'secret_settings' => ['refresh_token' => 'amazon-refresh-token'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->assertActionVisible('amazon_connect')
        ->assertActionVisible('amazon_disconnect')
        ->assertActionVisible('amazon_refresh_marketplaces')
        ->assertActionHidden('shopify_connect');
});

it('shows discovered Amazon marketplaces in the marketplace selector', function (): void {
    $this->actingAs($this->admin);
    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => [
            'marketplace_id' => 'A2Q3Y263D00KWC',
            'amazon_marketplaces' => [
                [
                    'id' => 'ATVPDKIKX0DER',
                    'name' => 'Amazon.com',
                    'country_code' => 'US',
                    'is_participating' => true,
                    'has_suspended_listings' => false,
                ],
                [
                    'id' => 'A2Q3Y263D00KWC',
                    'name' => 'Amazon.com.br',
                    'country_code' => 'BR',
                    'is_participating' => true,
                    'has_suspended_listings' => true,
                ],
            ],
        ],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->assertFormFieldExists('settings.marketplace_id', function ($field): bool {
            if (! $field instanceof Select) {
                return false;
            }

            $options = $field->getOptions();

            return count($options) === count(AmazonMarketplace::cases())
                && $options['ATVPDKIKX0DER'] === 'Amazon.com (US)'
                && $options['A2Q3Y263D00KWC'] === 'Amazon.com.br (BR) — Listings suspended';
        });
});

it('falls back to supported Amazon marketplaces when discovery returns none', function (): void {
    $this->actingAs($this->admin);
    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => ['amazon_marketplaces' => []],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->assertFormFieldExists('settings.marketplace_id', function ($field): bool {
            return $field instanceof Select && $field->getOptions() === AmazonMarketplace::options();
        });
});

it('preserves an existing Amazon marketplace outside the supported defaults when saving', function (): void {
    $this->actingAs($this->admin);
    $channel = Channel::factory()->create();
    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'active' => false,
        'settings' => [
            'marketplace_id' => 'A1F83G8C2ARO7P',
            'channel_name' => $channel->id,
            'amazon_marketplaces' => [],
        ],
        'secret_settings' => ['refresh_token' => 'amazon-refresh-token'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->assertFormFieldExists('settings.marketplace_id', function ($field): bool {
            return $field instanceof Select
                && $field->getOptions()['A1F83G8C2ARO7P'] === 'A1F83G8C2ARO7P (existing selection)';
        })
        ->call('save')
        ->assertHasNoFormErrors();

    expect($source->fresh()->settings['marketplace_id'])->toBe('A1F83G8C2ARO7P');
});

it('refreshes Amazon marketplaces from the edit page', function (): void {
    $this->actingAs($this->admin);
    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => ['auth_mode' => 'authorization_code'],
        'secret_settings' => ['refresh_token' => 'marketplace-refresh-token'],
    ]);
    Cache::put('amazon_sp_api_access_token_'.md5('marketplace-refresh-token'), 'marketplace-access-token', 3600);

    Saloon::fake([
        GetMarketplaceParticipations::class => MockResponse::make([
            'payload' => [
                [
                    'marketplace' => [
                        'id' => 'ATVPDKIKX0DER',
                        'name' => 'Amazon.com',
                        'countryCode' => 'US',
                    ],
                    'participation' => [
                        'isParticipating' => true,
                        'hasSuspendedListings' => false,
                    ],
                ],
            ],
        ]),
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->callAction('amazon_refresh_marketplaces')
        ->assertNotified('Amazon marketplaces refreshed')
        ->assertFormSet(['settings.marketplace_id' => 'ATVPDKIKX0DER']);

    expect($source->fresh()->settings['marketplace_id'])->toBe('ATVPDKIKX0DER');
});

it('disables Amazon marketplace refresh while sandbox mode is enabled', function (): void {
    app(SettingsService::class)->set('sandbox_mode', true);
    $this->actingAs($this->admin);
    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'settings' => ['auth_mode' => 'authorization_code', 'marketplace_id' => 'ATVPDKIKX0DER'],
        'secret_settings' => ['refresh_token' => 'amazon-refresh-token'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->assertActionDisabled('amazon_refresh_marketplaces');
});

it('prevents manually running an Amazon import without a selected marketplace', function (): void {
    $this->actingAs($this->admin);
    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'active' => true,
        'settings' => ['auth_mode' => 'authorization_code'],
        'secret_settings' => ['refresh_token' => 'amazon-refresh-token'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->assertActionDisabled('run_import');
});

it('blocks saving an Amazon data source as active on edit when MFA is not required', function (): void {
    $this->actingAs($this->admin);
    $channel = Channel::factory()->create();

    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'active' => false,
        'settings' => ['marketplace_id' => 'ATVPDKIKX0DER', 'channel_name' => $channel->id],
        'secret_settings' => ['refresh_token' => 'Atzr|existing-token'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->fillForm(['active' => true])
        ->call('save')
        ->assertHasFormErrors(['active']);

    expect($source->fresh()->active)->toBeFalse();
});

// ── FBA import toggle ─────────────────────────────────────────────────────────

it('offers the FBA import toggle for Amazon sources only, defaulting off', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm(['source_type' => AmazonSource::class])
        ->assertFormFieldVisible('settings.import_fba_orders')
        ->assertFormSet(['settings.import_fba_orders' => false])
        ->fillForm(['source_type' => DatabaseSource::class])
        ->assertFormFieldHidden('settings.import_fba_orders');
});
