<?php

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\Filament\Pages\ManualShip;
use App\Models\BoxSize;
use App\Models\Channel;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create(['auto_ship_enabled' => false]));
});

it('can render the manual ship page', function (): void {
    Livewire::test(ManualShip::class)->assertSuccessful();
});

it('creates a shipment and redirects to ship page', function (): void {
    Channel::factory()->create(['name' => 'Manual']);
    $box = BoxSize::factory()->create();
    $shippingMethod = ShippingMethod::factory()->create();

    Livewire::test(ManualShip::class)
        ->fillForm([
            'shipment_reference' => 'MAN-1001',
            'first_name' => 'Taylor',
            'last_name' => 'Jones',
            'address1' => '123 Main St',
            'city' => 'Seattle',
            'country' => 'US',
            'state_or_province' => 'WA',
            'postal_code' => '98101',
            'shipping_method_id' => $shippingMethod->id,
            'box_size_id' => $box->id,
            'weight' => 2.5,
            'height' => 10,
            'width' => 8,
            'length' => 6,
        ])
        ->call('ship')
        ->assertHasNoFormErrors();

    $shipment = Shipment::where('shipment_reference', 'MAN-1001')->first();

    expect($shipment)->not->toBeNull()
        ->and($shipment->first_name)->toBe('Taylor')
        ->and($shipment->shipping_method_id)->toBe($shippingMethod->id);

    expect(Package::where('shipment_id', $shipment->id)->exists())->toBeTrue();
});

it('uses the account auto-ship setting and redirects attended-only options to the ship page', function (): void {
    auth()->user()->update(['auto_ship_enabled' => true]);

    Channel::factory()->create(['name' => 'Manual']);
    $box = BoxSize::factory()->create();

    $workflow = Mockery::mock(PackageShippingWorkflow::class);
    $workflow->shouldReceive('autoShip')
        ->once()
        ->andReturn(PackageShippingResult::attendedSelectionRequired(
            'Attended Shipping Required',
            'Choose an available attended-only postage option.',
        ));
    app()->instance(PackageShippingWorkflow::class, $workflow);

    $component = Livewire::test(ManualShip::class)
        ->set('autoShipEnabled', false)
        ->fillForm([
            'shipment_reference' => 'MAN-AUTO-1',
            'first_name' => 'Sam',
            'last_name' => 'Lee',
            'address1' => '123 Main St',
            'city' => 'Seattle',
            'country' => 'US',
            'state_or_province' => 'WA',
            'postal_code' => '98101',
            'box_size_id' => $box->id,
            'weight' => 2.5,
            'height' => 10,
            'width' => 8,
            'length' => 6,
        ])
        ->call('ship')
        ->assertNotified('Attended Shipping Required');

    $shipment = Shipment::where('shipment_reference', 'MAN-AUTO-1')->firstOrFail();
    $package = Package::where('shipment_id', $shipment->id)->firstOrFail();

    $component->assertRedirect('/ship/'.$package->id);

    expect(session('ship_return_url'))->toBe('/manual-ship');
});

it('rejects shipping when no name or company is provided', function (): void {
    Channel::factory()->create(['name' => 'Manual']);
    $box = BoxSize::factory()->create();

    Livewire::test(ManualShip::class)
        ->fillForm([
            'first_name' => '',
            'last_name' => '',
            'company' => '',
            'address1' => '123 Main St',
            'city' => 'Seattle',
            'country' => 'US',
            'state_or_province' => 'WA',
            'postal_code' => '98101',
            'box_size_id' => $box->id,
            'weight' => 2.5,
            'height' => 10,
            'width' => 8,
            'length' => 6,
        ])
        ->call('ship')
        ->assertNotified('Missing Info');

    expect(Shipment::count())->toBe(0);
});

it('redirects to pack page without overriding the account auto-ship policy when scan_to_add_enabled is on', function (): void {
    Setting::create(['key' => 'scan_to_add_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    Channel::factory()->create(['name' => 'Manual']);
    $box = BoxSize::factory()->create();

    $component = Livewire::test(ManualShip::class)
        ->fillForm([
            'shipment_reference' => 'MAN-2001',
            'first_name' => 'Alex',
            'last_name' => 'Smith',
            'address1' => '456 Pine Ave',
            'city' => 'Portland',
            'country' => 'US',
            'state_or_province' => 'OR',
            'postal_code' => '97201',
            'box_size_id' => $box->id,
            'weight' => 1.5,
            'height' => 8,
            'width' => 6,
            'length' => 4,
        ])
        ->call('ship')
        ->assertHasNoFormErrors();

    $shipment = Shipment::where('shipment_reference', 'MAN-2001')->first();
    expect($shipment)->not->toBeNull();

    $package = Package::where('shipment_id', $shipment->id)->first();
    expect($package)->not->toBeNull()
        ->and((float) $package->weight)->toBe(1.5);

    expect(session()->has('pack_auto_ship_override'))->toBeFalse();

    $component->assertRedirect('/pack/'.$shipment->id);
});
