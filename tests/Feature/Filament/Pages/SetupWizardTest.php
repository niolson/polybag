<?php

use App\Enums\CarrierPackaging;
use App\Filament\Pages\SetupWizard;
use App\Models\BoxSize;
use App\Models\Channel;
use App\Models\DataSource;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Setting::updateOrCreate(
        ['key' => 'setup_complete'],
        ['value' => '0', 'type' => 'boolean', 'group' => 'system'],
    );

    app(SettingsService::class)->clearCache();
});

it('renders the setup wizard when setup is incomplete', function (): void {
    Livewire::test(SetupWizard::class)
        ->assertSuccessful()
        ->assertSet('data.prepopulate_box_sizes', false)
        ->assertSet('data.prepopulate_shipping_methods', false);
});

it('prepopulates starter box sizes when selected', function (): void {
    $component = Livewire::test(SetupWizard::class)
        ->tap(fn ($component) => fillRequiredSetupWizardFields($component))
        ->set('data.prepopulate_box_sizes', true);

    invokePrivateMethod($component->instance(), 'saveBoxSizes');

    expect(BoxSize::count())->toBeGreaterThan(0)
        ->and(BoxSize::where('code', '01')->exists())->toBeTrue()
        ->and(BoxSize::where('label', 'USPS Flat Rate Padded Envelope')->exists())->toBeTrue();
});

it('creates box sizes with and without carrier packaging', function (): void {
    $component = Livewire::test(SetupWizard::class)
        ->tap(fn ($component) => fillRequiredSetupWizardFields($component))
        ->set('data.box_sizes', [
            [
                'label' => 'FedEx Pak',
                'code' => 'PAK',
                'type' => 'PADDED_MAILER',
                'height' => 1,
                'width' => 11.75,
                'length' => 14.75,
                'max_weight' => 35,
                'empty_weight' => 0.1,
                'carrier_packaging' => CarrierPackaging::FedexPak->value,
            ],
            [
                'label' => '6x6x6',
                'code' => 'CUBE',
                'type' => 'BOX',
                'height' => 6,
                'width' => 6,
                'length' => 6,
                'max_weight' => 35,
                'empty_weight' => 0.2,
                'carrier_packaging' => null,
            ],
        ]);

    invokePrivateMethod($component->instance(), 'saveBoxSizes');

    expect(BoxSize::where('code', 'PAK')->sole()->carrier_packaging)->toBe(CarrierPackaging::FedexPak)
        ->and(BoxSize::where('code', 'CUBE')->sole()->carrier_packaging)->toBeNull();
});

it('prepopulates starter shipping methods when selected', function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $component = Livewire::test(SetupWizard::class)
        ->tap(fn ($component) => fillRequiredSetupWizardFields($component))
        ->set('data.prepopulate_shipping_methods', true);

    invokePrivateMethod($component->instance(), 'saveChannelsAndMethods');

    $standardGround = ShippingMethod::where('name', 'Standard Ground')->first();

    expect(ShippingMethod::count())->toBeGreaterThan(0)
        ->and($standardGround)->not->toBeNull()
        ->and($standardGround->carrierServices()->count())->toBeGreaterThan(0)
        ->and(ShippingMethodAlias::where('shipping_method_id', $standardGround->id)->where('reference', '1')->exists())->toBeTrue();
});

// ── Data source step ──────────────────────────────────────────────────────────

it('creates a database DataSource record from the import source step', function (): void {
    $component = Livewire::test(SetupWizard::class)
        ->tap(fn ($component) => fillRequiredSetupWizardFields($component))
        ->set('data.import_source', 'database')
        ->set('data.db_driver', 'mysql')
        ->set('data.db_host', 'db.example.com')
        ->set('data.db_port', '3307')
        ->set('data.db_database', 'orders')
        ->set('data.db_username', 'reader')
        ->set('data.db_password', 'supersecret');

    invokePrivateMethod($component->instance(), 'saveImportSource');

    $record = DataSource::where('source_type', DatabaseSource::class)->firstOrFail();

    expect($record->active)->toBeTrue()
        ->and($record->settings['db_driver'])->toBe('mysql')
        ->and($record->settings['db_host'])->toBe('db.example.com')
        ->and($record->settings['db_port'])->toBe(3307)
        ->and($record->settings['db_database'])->toBe('orders')
        ->and($record->settings['db_username'])->toBe('reader')
        ->and($record->settings)->not->toHaveKey('db_password')
        ->and($record->secret('db_password'))->toBe('supersecret');
});

it('does not duplicate the DataSource when the import step is saved twice', function (): void {
    $component = Livewire::test(SetupWizard::class)
        ->tap(fn ($component) => fillRequiredSetupWizardFields($component))
        ->set('data.import_source', 'database')
        ->set('data.db_driver', 'mysql')
        ->set('data.db_host', 'db.example.com')
        ->set('data.db_port', '3306')
        ->set('data.db_database', 'orders')
        ->set('data.db_username', 'reader')
        ->set('data.db_password', 'supersecret');

    invokePrivateMethod($component->instance(), 'saveImportSource');

    // Revisit the step: blank password must not wipe the stored secret
    $component->set('data.db_password', null)
        ->set('data.db_host', 'db2.example.com');

    invokePrivateMethod($component->instance(), 'saveImportSource');

    expect(DataSource::count())->toBe(1);

    $record = DataSource::where('source_type', DatabaseSource::class)->firstOrFail();

    expect($record->settings['db_host'])->toBe('db2.example.com')
        ->and($record->secret('db_password'))->toBe('supersecret');
});

it('creates a shopify DataSource record with the shop domain and an auto-created channel', function (): void {
    $component = Livewire::test(SetupWizard::class)
        ->tap(fn ($component) => fillRequiredSetupWizardFields($component))
        ->set('data.import_source', 'shopify')
        ->set('data.shopify_shop_domain', 'acme.myshopify.com');

    invokePrivateMethod($component->instance(), 'saveImportSource');

    $record = DataSource::where('source_type', ShopifySource::class)->firstOrFail();
    $channel = Channel::where('name', 'Shopify')->firstOrFail();

    expect($record->settings['shop_domain'])->toBe('acme.myshopify.com')
        ->and($record->settings['channel_name'])->toBe($channel->id)
        // Activation is guarded and happens later; see ShopifyFulfillmentOrderActivationService.
        ->and($record->settings)->not->toHaveKey('fulfillment_order_import_enabled')
        ->and($record->settings)->not->toHaveKey('authoritative_shipment_items');
});

it('creates a shopify DataSource record using the selected channel', function (): void {
    $channel = Channel::factory()->create(['name' => 'My Shopify Store']);

    $component = Livewire::test(SetupWizard::class)
        ->tap(fn ($component) => fillRequiredSetupWizardFields($component))
        ->set('data.import_source', 'shopify')
        ->set('data.shopify_shop_domain', 'acme.myshopify.com')
        ->set('data.shopify_channel_id', $channel->id);

    invokePrivateMethod($component->instance(), 'saveImportSource');

    $record = DataSource::where('source_type', ShopifySource::class)->firstOrFail();

    expect($record->settings['channel_name'])->toBe($channel->id)
        ->and(Channel::where('name', 'Shopify')->exists())->toBeFalse();
});

it('creates an amazon DataSource record from the import source step with an auto-created channel', function (): void {
    $component = Livewire::test(SetupWizard::class)
        ->tap(fn ($component) => fillRequiredSetupWizardFields($component))
        ->set('data.import_source', 'amazon')
        ->set('data.amazon_marketplace_id', 'ATVPDKIKX0DER');

    invokePrivateMethod($component->instance(), 'saveImportSource');

    $record = DataSource::where('source_type', AmazonSource::class)->firstOrFail();
    $channel = Channel::where('name', 'Amazon')->firstOrFail();

    expect($record->settings['marketplace_id'])->toBe('ATVPDKIKX0DER')
        ->and($record->settings['channel_name'])->toBe($channel->id);
});

it('creates an amazon DataSource record using the selected channel', function (): void {
    $channel = Channel::factory()->create(['name' => 'Amazon US']);

    $component = Livewire::test(SetupWizard::class)
        ->tap(fn ($component) => fillRequiredSetupWizardFields($component))
        ->set('data.import_source', 'amazon')
        ->set('data.amazon_marketplace_id', 'ATVPDKIKX0DER')
        ->set('data.amazon_channel_id', $channel->id);

    invokePrivateMethod($component->instance(), 'saveImportSource');

    $record = DataSource::where('source_type', AmazonSource::class)->firstOrFail();

    expect($record->settings['channel_name'])->toBe($channel->id)
        ->and(Channel::where('name', 'Amazon')->exists())->toBeFalse();
});

it('creates no DataSource when import source is none', function (): void {
    $component = Livewire::test(SetupWizard::class)
        ->tap(fn ($component) => fillRequiredSetupWizardFields($component));

    invokePrivateMethod($component->instance(), 'saveImportSource');

    expect(DataSource::count())->toBe(0);
});

it('prefills the import source select from an existing DataSource', function (): void {
    DataSource::factory()->create(['source_type' => DatabaseSource::class]);

    Livewire::test(SetupWizard::class)
        ->assertSet('data.import_source', 'database');
});

function invokePrivateMethod(object $instance, string $method): void
{
    $reflection = new ReflectionMethod($instance, $method);
    $reflection->setAccessible(true);
    $reflection->invoke($instance);
}

function fillRequiredSetupWizardFields(Testable $component): void
{
    $requiredData = [
        'company_name' => 'Acme Fulfillment',
        'location_name' => 'Main Warehouse',
        'location_first_name' => 'Jane',
        'location_last_name' => 'Doe',
        'location_address1' => '123 Market Street',
        'location_city' => 'Philadelphia',
        'location_country' => 'US',
        'location_state_or_province' => 'PA',
        'location_postal_code' => '19106',
        'location_phone' => '+12155550123',
        'location_timezone' => 'America/New_York',
    ];

    foreach ($requiredData as $key => $value) {
        $component->set("data.{$key}", $value);
    }
}
