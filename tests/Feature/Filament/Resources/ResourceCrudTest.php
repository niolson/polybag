<?php

use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Enums\Role;
use App\Filament\Pages\Settings;
use App\Filament\Resources\BoxSizeResource\Pages\CreateBoxSize;
use App\Filament\Resources\BoxSizeResource\Pages\EditBoxSize;
use App\Filament\Resources\BoxSizeResource\Pages\ListBoxSizes;
use App\Filament\Resources\CarrierServiceResource\Pages\CreateCarrierService;
use App\Filament\Resources\CarrierServiceResource\Pages\EditCarrierService;
use App\Filament\Resources\CarrierServiceResource\Pages\ListCarrierServices;
use App\Filament\Resources\ChannelResource\Pages\CreateChannel;
use App\Filament\Resources\ChannelResource\Pages\EditChannel;
use App\Filament\Resources\ChannelResource\Pages\ListChannels;
use App\Filament\Resources\LocationResource;
use App\Filament\Resources\LocationResource\Pages\CreateLocation;
use App\Filament\Resources\LocationResource\Pages\EditLocation;
use App\Filament\Resources\LocationResource\Pages\ListLocations;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ShippingMethodResource\Pages\CreateShippingMethod;
use App\Filament\Resources\ShippingMethodResource\Pages\EditShippingMethod;
use App\Filament\Resources\ShippingMethodResource\Pages\ListShippingMethods;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Channel;
use App\Models\Location;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

// BoxSizeResource

it('can render BoxSize list page', function (): void {
    Livewire::test(ListBoxSizes::class)->assertSuccessful();
});

it('can render BoxSize create page', function (): void {
    Livewire::test(CreateBoxSize::class)->assertSuccessful();
});

it('can render BoxSize edit page', function (): void {
    $record = BoxSize::factory()->create();
    Livewire::test(EditBoxSize::class, ['record' => $record->id])->assertSuccessful();
});

it('can create a BoxSize', function (): void {
    Livewire::test(CreateBoxSize::class)
        ->fillForm([
            'label' => 'Small Box',
            'code' => 'SM-BOX',
            'type' => BoxSizeType::BOX->value,
            'height' => 6,
            'width' => 8,
            'length' => 10,
            'max_weight' => 25,
            'empty_weight' => 0.5,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(BoxSize::class, [
        'label' => 'Small Box',
        'code' => 'SM-BOX',
        'type' => 'BOX',
    ]);
});

it('can edit a BoxSize', function (): void {
    $record = BoxSize::factory()->create();

    Livewire::test(EditBoxSize::class, ['record' => $record->id])
        ->fillForm([
            'label' => 'Updated Box',
            'max_weight' => 50,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->refresh())
        ->label->toBe('Updated Box')
        ->max_weight->toEqual(50);
});

it('can create a BoxSize that is carrier-supplied packaging', function (): void {
    Livewire::test(CreateBoxSize::class)
        ->fillForm([
            'label' => 'USPS Medium Flat Rate Box',
            'code' => 'MFRB',
            'type' => BoxSizeType::BOX->value,
            'carrier_packaging' => CarrierPackaging::UspsMediumFlatRateBox->value,
            'height' => 5.5,
            'width' => 8.5,
            'length' => 11,
            'max_weight' => 70,
            'empty_weight' => 0.4,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BoxSize::where('code', 'MFRB')->sole()->carrier_packaging)->toBe(CarrierPackaging::UspsMediumFlatRateBox);
});

it('can edit a BoxSize between carrier-supplied packaging and its own', function (): void {
    $record = BoxSize::factory()->create();

    Livewire::test(EditBoxSize::class, ['record' => $record->id])
        ->assertFormSet(['carrier_packaging' => null])
        ->fillForm(['carrier_packaging' => CarrierPackaging::FedexPak->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->refresh()->carrier_packaging)->toBe(CarrierPackaging::FedexPak);

    Livewire::test(EditBoxSize::class, ['record' => $record->id])
        ->assertFormSet(['carrier_packaging' => CarrierPackaging::FedexPak->value])
        ->fillForm(['carrier_packaging' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->refresh()->carrier_packaging)->toBeNull();
});

// CarrierServiceResource

it('can render CarrierService list page', function (): void {
    Livewire::test(ListCarrierServices::class)->assertSuccessful();
});

it('can render CarrierService create page', function (): void {
    Livewire::test(CreateCarrierService::class)->assertSuccessful();
});

it('can render CarrierService edit page', function (): void {
    $carrier = Carrier::factory()->create();
    $record = CarrierService::factory()->create(['carrier_id' => $carrier->id]);
    Livewire::test(EditCarrierService::class, ['record' => $record->id])->assertSuccessful();
});

it('can create a CarrierService', function (): void {
    $carrier = Carrier::factory()->create();

    Livewire::test(CreateCarrierService::class)
        ->fillForm([
            'carrier_id' => $carrier->id,
            'service_code' => 'TEST_SVC',
            'name' => 'Test Service',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(CarrierService::class, [
        'carrier_id' => $carrier->id,
        'service_code' => 'TEST_SVC',
        'name' => 'Test Service',
    ]);
});

it('can edit a CarrierService', function (): void {
    $carrier = Carrier::factory()->create();
    $record = CarrierService::factory()->create(['carrier_id' => $carrier->id]);

    Livewire::test(EditCarrierService::class, ['record' => $record->id])
        ->fillForm([
            'name' => 'Renamed Service',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->refresh())->name->toBe('Renamed Service');
});

// LocationResource

it('can render Location list page in multi-location mode', function (): void {
    Setting::create(['key' => 'multi_location_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    Livewire::test(ListLocations::class)->assertSuccessful();
});

it('redirects Location list to Settings in single-location mode', function (): void {
    Livewire::test(ListLocations::class)
        ->assertRedirect(Settings::getUrl());
});

it('can render Location create page', function (): void {
    Livewire::test(CreateLocation::class)->assertSuccessful();
});

it('can render Location edit page', function (): void {
    $record = Location::factory()->create();
    Livewire::test(EditLocation::class, ['record' => $record->id])->assertSuccessful();
});

it('shows the fedex hub selector when fedex is active', function (): void {
    Carrier::factory()->fedex()->create(['active' => true]);

    $this->get(LocationResource::getUrl('create'))
        ->assertOk()
        ->assertSeeText('FedEx Hub ID');
});

it('hides the fedex hub selector when fedex is inactive', function (): void {
    Carrier::factory()->fedex()->create(['active' => false]);

    $this->get(LocationResource::getUrl('create'))
        ->assertOk()
        ->assertDontSeeText('FedEx Hub ID');
});

it('can create a Location with a fedex hub id when fedex is active', function (): void {
    Carrier::factory()->fedex()->create(['active' => true]);

    Livewire::test(CreateLocation::class)
        ->fillForm([
            'name' => 'East Coast Warehouse',
            'is_default' => true,
            'active' => true,
            'timezone' => 'America/New_York',
            'fedex_hub_id' => '5015',
            'company' => 'PolyBag',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'address1' => '123 Main St',
            'city' => 'Boston',
            'country' => 'US',
            'state_or_province' => 'MA',
            'postal_code' => '02110',
            'phone' => '+16175551212',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Location::class, [
        'name' => 'East Coast Warehouse',
        'fedex_hub_id' => '5015',
    ]);
});

it('rejects a location phone number that does not parse for the selected country', function (): void {
    Livewire::test(CreateLocation::class)
        ->fillForm([
            'name' => 'Mexico Warehouse',
            'is_default' => true,
            'active' => true,
            'timezone' => 'America/Mexico_City',
            'company' => 'PolyBag',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'address1' => 'Av. Vasco De Quiroga 2999',
            'city' => 'Mexico City',
            'country' => 'MX',
            'state_or_province' => 'CMX',
            'postal_code' => '01210',
            'phone' => '4155550132',
        ])
        ->call('create');

    $this->assertDatabaseMissing(Location::class, [
        'name' => 'Mexico Warehouse',
    ]);
});

// ChannelResource

it('can render Channel list page', function (): void {
    Livewire::test(ListChannels::class)->assertSuccessful();
});

it('can render Channel create page', function (): void {
    Livewire::test(CreateChannel::class)->assertSuccessful();
});

it('can render Channel edit page', function (): void {
    $record = Channel::factory()->create();
    Livewire::test(EditChannel::class, ['record' => $record->id])->assertSuccessful();
});

it('can create a Channel', function (): void {
    Livewire::test(CreateChannel::class)
        ->fillForm([
            'name' => 'Test Channel',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Channel::class, [
        'name' => 'Test Channel',
    ]);
});

it('can edit a Channel', function (): void {
    $record = Channel::factory()->create();

    Livewire::test(EditChannel::class, ['record' => $record->id])
        ->fillForm([
            'name' => 'Updated Channel',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->refresh())->name->toBe('Updated Channel');
});

// ProductResource

it('can render Product list page', function (): void {
    Livewire::test(ListProducts::class)->assertSuccessful();
});

it('can render Product create page', function (): void {
    Livewire::test(CreateProduct::class)->assertSuccessful();
});

it('can render Product edit page', function (): void {
    $record = Product::factory()->create();
    Livewire::test(EditProduct::class, ['record' => $record->id])->assertSuccessful();
});

it('can create a Product', function (): void {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Widget',
            'sku' => 'WDG-001',
            'weight' => 1.5,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Product::class, [
        'name' => 'Widget',
        'sku' => 'WDG-001',
    ]);
});

it('can edit a Product', function (): void {
    $record = Product::factory()->create();

    Livewire::test(EditProduct::class, ['record' => $record->id])
        ->fillForm([
            'name' => 'Updated Widget',
            'weight' => 3.0,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->refresh())
        ->name->toBe('Updated Widget')
        ->weight->toEqual(3);
});

// ShippingMethodResource

it('can render ShippingMethod list page', function (): void {
    Livewire::test(ListShippingMethods::class)->assertSuccessful();
});

it('can render ShippingMethod create page', function (): void {
    Livewire::test(CreateShippingMethod::class)->assertSuccessful();
});

it('can render ShippingMethod edit page', function (): void {
    $record = ShippingMethod::factory()->create();
    Livewire::test(EditShippingMethod::class, ['record' => $record->id])->assertSuccessful();
});

it('can create a ShippingMethod', function (): void {
    Livewire::test(CreateShippingMethod::class)
        ->fillForm([
            'name' => 'Express Shipping',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(ShippingMethod::class, [
        'name' => 'Express Shipping',
    ]);
});

it('can edit a ShippingMethod', function (): void {
    $record = ShippingMethod::factory()->create();

    Livewire::test(EditShippingMethod::class, ['record' => $record->id])
        ->fillForm([
            'name' => 'Updated Method',
            'commitment_days' => 3,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->refresh())
        ->name->toBe('Updated Method')
        ->commitment_days->toBe(3);
});

// UserResource

it('can render User list page', function (): void {
    Livewire::test(ListUsers::class)->assertSuccessful();
});

it('can render User create page', function (): void {
    Livewire::test(CreateUser::class)->assertSuccessful();
});

it('can render User edit page', function (): void {
    $record = User::factory()->create();
    Livewire::test(EditUser::class, ['record' => $record->id])->assertSuccessful();
});

it('shows the live password policy checklist on the User create page', function (): void {
    Livewire::test(CreateUser::class)
        ->assertSee('Password must include:')
        ->assertSee('At least 12 characters')
        ->assertSee('At least one symbol');
});

it('shows the live password policy checklist on the User edit page', function (): void {
    $record = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $record->id])
        ->assertSee('Password must include:')
        ->assertSee('At least 12 characters');
});

it('can create a User', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'username' => 'janedoe',
            'password' => 'SecurePass123!456',
            'role' => Role::User->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(User::class, [
        'name' => 'Jane Doe',
        'username' => 'janedoe',
        'role' => 'user',
    ]);
});

it('can edit a User', function (): void {
    $record = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $record->id])
        ->fillForm([
            'name' => 'Updated Name',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->refresh())->name->toBe('Updated Name');
});

it('turns auto ship on by default for a new User', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'username' => 'janedoe',
            'password' => 'SecurePass123!456',
            'role' => Role::User->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::firstWhere('username', 'janedoe')->auto_ship_enabled)->toBeTrue();
});

it('lets an admin turn auto ship off for any User, including themselves', function (): void {
    $admin = auth()->user();
    $admin->update(['auto_ship_enabled' => true]);
    $manager = User::factory()->manager()->create(['auto_ship_enabled' => true]);

    foreach ([$admin, $manager] as $record) {
        Livewire::test(EditUser::class, ['record' => $record->id])
            ->fillForm(['auto_ship_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($record->refresh()->auto_ship_enabled)->toBeFalse();
    }
});

it('enforces the password policy when an admin sets a User password', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'username' => 'janedoe',
            'password' => 'weakpass',
            'role' => Role::User->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);
});

it('rejects reusing a User\'s current password from the admin edit form', function (): void {
    $record = User::factory()->create(['password' => Hash::make('CurrentPass123!456')]);

    Livewire::test(EditUser::class, ['record' => $record->id])
        ->fillForm(['password' => 'CurrentPass123!456'])
        ->call('save')
        ->assertHasFormErrors(['password']);
});
