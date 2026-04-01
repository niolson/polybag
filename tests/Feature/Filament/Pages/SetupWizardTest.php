<?php

use App\Filament\Pages\SetupWizard;
use App\Models\BoxSize;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodAlias;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\ReferenceDataSeeder;
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

function invokePrivateMethod(object $instance, string $method): void
{
    $reflection = new ReflectionMethod($instance, $method);
    $reflection->setAccessible(true);
    $reflection->invoke($instance);
}

function fillRequiredSetupWizardFields(\Livewire\Features\SupportTesting\Testable $component): void
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
        'location_timezone' => 'America/New_York',
    ];

    foreach ($requiredData as $key => $value) {
        $component->set("data.{$key}", $value);
    }
}
