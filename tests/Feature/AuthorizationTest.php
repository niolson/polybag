<?php

use App\Contracts\DirectCarrierAdapter;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Pages\EndOfDay;
use App\Filament\Pages\ManualShip;
use App\Filament\Pages\Settings;
use App\Filament\Pages\UnmappedObservedServices;
use App\Filament\Pages\UnmappedShippingReferences;
use App\Filament\Resources\BoxSizeResource\Pages\ListBoxSizes;
use App\Filament\Resources\CarrierAccounts\Pages\CreateCarrierAccount;
use App\Filament\Resources\CarrierAccounts\Pages\EditCarrierAccount;
use App\Filament\Resources\CarrierAccounts\Pages\ListCarrierAccounts;
use App\Filament\Resources\Carriers\Pages\ListCarriers;
use App\Filament\Resources\CarrierServiceResource\Pages\ListCarrierServices;
use App\Filament\Resources\ChannelResource\Pages\ListChannels;
use App\Filament\Resources\LocationResource\Pages\EditLocation;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\PackageResource\Pages\ViewPackage;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ShipmentResource\Pages\CreateShipment;
use App\Filament\Resources\ShipmentResource\Pages\EditShipment;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Filament\Resources\ShippingMethodResource\Pages\ListShippingMethods;
use App\Filament\Resources\SpecialServices\Pages\ListSpecialServices;
use App\Filament\Resources\SpecialServices\SpecialServiceResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Widgets\CarrierBreakdownChart;
use App\Filament\Widgets\CostPerPackageTrend;
use App\Filament\Widgets\ExceptionsWidget;
use App\Filament\Widgets\ShippedShipmentsChart;
use App\Models\CarrierAccountScope;
use App\Models\Location;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use App\Services\SettingsService;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use PHPUnit\Framework\ExpectationFailedException;

// Carrier accounts are covered by 'carrier accounts are Admin-only' below.
it('restricts special services to admins', function (Role $role): void {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $isAdmin = $role === Role::Admin;

    expect(SpecialServiceResource::canViewAny())->toBe($isAdmin);

    Livewire::test(ListSpecialServices::class)->assertStatus($isAdmin ? 200 : 403);
})->with([Role::User, Role::Manager, Role::Admin]);

it('shows reporting widgets to managers and admins but not shippers', function (Role $role): void {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $isManager = $role->isAtLeast(Role::Manager);

    foreach ([CarrierBreakdownChart::class, CostPerPackageTrend::class, ExceptionsWidget::class, ShippedShipmentsChart::class] as $widget) {
        expect($widget::canView())->toBe($isManager);
    }
})->with([Role::User, Role::Manager, Role::Admin]);

describe('user role access', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::User]));
    });

    // Resources accessible to all roles
    it('can list shipments', function (): void {
        Livewire::test(ListShipments::class)->assertSuccessful();
    });

    it('can list packages', function (): void {
        Livewire::test(ListPackages::class)->assertSuccessful();
    });

    // Resources NOT accessible to user role
    it('cannot create shipments', function (): void {
        Livewire::test(CreateShipment::class)->assertForbidden();
    });

    it('cannot edit shipments', function (): void {
        $shipment = Shipment::factory()->create();
        Livewire::test(EditShipment::class, ['record' => $shipment->getRouteKey()])->assertForbidden();
    });

    it('cannot list box sizes', function (): void {
        Livewire::test(ListBoxSizes::class)->assertForbidden();
    });

    it('cannot list products', function (): void {
        Livewire::test(ListProducts::class)->assertForbidden();
    });

    it('cannot list shipping methods', function (): void {
        Livewire::test(ListShippingMethods::class)->assertForbidden();
    });

    it('cannot list users', function (): void {
        Livewire::test(ListUsers::class)->assertForbidden();
    });

    it('cannot list carriers', function (): void {
        Livewire::test(ListCarriers::class)->assertForbidden();
    });

    it('cannot list carrier services', function (): void {
        Livewire::test(ListCarrierServices::class)->assertForbidden();
    });

    it('cannot list channels', function (): void {
        Livewire::test(ListChannels::class)->assertForbidden();
    });

    it('cannot access app settings page', function (): void {
        Livewire::test(Settings::class)->assertForbidden();
    });

    it('cannot access unmapped references page', function (): void {
        Livewire::test(UnmappedShippingReferences::class)->assertForbidden();
    });

    it('cannot access observed services mapping page', function (): void {
        Livewire::test(UnmappedObservedServices::class)->assertForbidden();
    });

    it('cannot access end of day page', function (): void {
        Livewire::test(EndOfDay::class)->assertForbidden();
    });

    it('cannot access manual ship page', function (): void {
        Livewire::test(ManualShip::class)->assertForbidden();
    });
});

describe('manager role access', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->manager()->create());
    });

    // Resources accessible to manager
    it('can list shipments', function (): void {
        Livewire::test(ListShipments::class)->assertSuccessful();
    });

    it('can edit shipments', function (): void {
        $shipment = Shipment::factory()->create();
        Livewire::test(EditShipment::class, ['record' => $shipment->getRouteKey()])->assertSuccessful();
    });

    it('can list packages', function (): void {
        Livewire::test(ListPackages::class)->assertSuccessful();
    });

    it('can list box sizes', function (): void {
        Livewire::test(ListBoxSizes::class)->assertSuccessful();
    });

    it('can list products', function (): void {
        Livewire::test(ListProducts::class)->assertSuccessful();
    });

    it('can list shipping methods', function (): void {
        Livewire::test(ListShippingMethods::class)->assertSuccessful();
    });

    it('can access unmapped references page', function (): void {
        Livewire::test(UnmappedShippingReferences::class)->assertSuccessful();
    });

    it('cannot access observed services mapping page, which is Admin-only', function (): void {
        Livewire::test(UnmappedObservedServices::class)->assertForbidden();
    });

    it('can access end of day page', function (): void {
        Livewire::test(EndOfDay::class)->assertSuccessful();
    });

    it('can access manual ship page', function (): void {
        Livewire::test(ManualShip::class)->assertSuccessful();
    });

    // Resources NOT accessible to manager
    it('cannot create shipments', function (): void {
        Livewire::test(CreateShipment::class)->assertForbidden();
    });

    it('cannot list users', function (): void {
        Livewire::test(ListUsers::class)->assertForbidden();
    });

    it('cannot list carriers', function (): void {
        Livewire::test(ListCarriers::class)->assertForbidden();
    });

    it('cannot list carrier services', function (): void {
        Livewire::test(ListCarrierServices::class)->assertForbidden();
    });

    it('cannot list channels', function (): void {
        Livewire::test(ListChannels::class)->assertForbidden();
    });

    it('cannot access app settings page', function (): void {
        Livewire::test(Settings::class)->assertForbidden();
    });
});

describe('admin role access', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->create());
    });

    // Admin can access everything
    it('can list shipments', function (): void {
        Livewire::test(ListShipments::class)->assertSuccessful();
    });

    it('can create shipments', function (): void {
        Livewire::test(CreateShipment::class)->assertSuccessful();
    });

    it('can list packages', function (): void {
        Livewire::test(ListPackages::class)->assertSuccessful();
    });

    it('can list box sizes', function (): void {
        Livewire::test(ListBoxSizes::class)->assertSuccessful();
    });

    it('can list products', function (): void {
        Livewire::test(ListProducts::class)->assertSuccessful();
    });

    it('can list shipping methods', function (): void {
        Livewire::test(ListShippingMethods::class)->assertSuccessful();
    });

    it('can list users', function (): void {
        Livewire::test(ListUsers::class)->assertSuccessful();
    });

    it('can list carriers', function (): void {
        Livewire::test(ListCarriers::class)->assertSuccessful();
    });

    it('can list carrier services', function (): void {
        Livewire::test(ListCarrierServices::class)->assertSuccessful();
    });

    it('can list channels', function (): void {
        Livewire::test(ListChannels::class)->assertSuccessful();
    });

    it('can access app settings page', function (): void {
        Livewire::test(Settings::class)->assertSuccessful();
    });

    it('can access unmapped references page', function (): void {
        Livewire::test(UnmappedShippingReferences::class)->assertSuccessful();
    });

    it('can access observed services mapping page', function (): void {
        Livewire::test(UnmappedObservedServices::class)->assertSuccessful();
    });

    it('can access end of day page', function (): void {
        Livewire::test(EndOfDay::class)->assertSuccessful();
    });
});

describe('carrier accounts are Admin-only', function (): void {
    beforeEach(function (): void {
        $this->account = createUpsAccount();
    });

    it('keeps a :dataset out of carrier accounts', function (Role $role): void {
        $this->actingAs(User::factory()->create(['role' => $role]));

        Livewire::test(ListCarrierAccounts::class)->assertForbidden();
        Livewire::test(CreateCarrierAccount::class)->assertForbidden();
        Livewire::test(EditCarrierAccount::class, ['record' => $this->account->id])->assertForbidden();
    })->with(['User' => Role::User, 'Manager' => Role::Manager]);

    it('does not let a :dataset change the billing account', function (Role $role): void {
        $this->actingAs(User::factory()->create(['role' => $role]));

        $component = Livewire::test(EditCarrierAccount::class, ['record' => $this->account->id]);
        $component->assertForbidden();

        try {
            $component->fillForm(['ups_account_number' => 'Z9Y8X7'])->call('save');
        } catch (Error) {
            // A forbidden page mounts no form to fill or save.
        }

        expect($this->account->fresh()->credentials['account_number'])->toBe('A1B2C3');
    })->with(['User' => Role::User, 'Manager' => Role::Manager]);

    it('lets an Admin manage carrier accounts', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListCarrierAccounts::class)
            ->assertActionVisible(TestAction::make(DeleteBulkAction::class)->table()->bulk())
            ->assertSuccessful();
        Livewire::test(CreateCarrierAccount::class)->assertSuccessful();
        Livewire::test(EditCarrierAccount::class, ['record' => $this->account->id])
            ->fillForm(['ups_account_number' => 'Z9Y8X7'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->account->fresh()->credentials['account_number'])->toBe('Z9Y8X7');
    });
});

describe('assigning carrier accounts to a location', function (): void {
    beforeEach(function (): void {
        Setting::create(['key' => 'multi_location_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
        app(SettingsService::class)->clearCache();

        $this->account = createUpsAccount();
        $this->location = Location::factory()->create();
    });

    it('hides the carrier accounts section from a Manager', function (): void {
        $this->actingAs(User::factory()->manager()->create());

        Livewire::test(EditLocation::class, ['record' => $this->location->id])
            ->assertFormFieldHidden('carrierAccountScopes');
    });

    it('does not let a Manager route a location to a carrier account', function (): void {
        $this->actingAs(User::factory()->manager()->create());

        Livewire::test(EditLocation::class, ['record' => $this->location->id])
            ->set('data.carrierAccountScopes', ['new' => ['carrier_account_id' => $this->account->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(CarrierAccountScope::where('location_id', $this->location->id)->exists())->toBeFalse();
    });

    it('keeps a location\'s carrier accounts when a Manager saves it', function (): void {
        CarrierAccountScope::create(['carrier_account_id' => $this->account->id, 'location_id' => $this->location->id]);
        $this->actingAs(User::factory()->manager()->create());

        Livewire::test(EditLocation::class, ['record' => $this->location->id])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->location->fresh()->name)->toBe('Renamed')
            ->and(CarrierAccountScope::where('location_id', $this->location->id)->count())->toBe(1);
    });

    it('lets an Admin route a location to a carrier account', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditLocation::class, ['record' => $this->location->id])
            ->assertFormFieldVisible('carrierAccountScopes')
            ->set('data.carrierAccountScopes', ['new' => ['carrier_account_id' => $this->account->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(CarrierAccountScope::where('location_id', $this->location->id)->value('carrier_account_id'))->toBe($this->account->id);
    });
});

describe('voiding a label', function (): void {
    beforeEach(function (): void {
        $adapter = Mockery::mock(DirectCarrierAdapter::class);
        $adapter->shouldReceive('cancelShipment')->andReturn(CancelResponse::success('voided'));
        app(CarrierRegistry::class)->registerInstance('USPS', $adapter);

        $this->shipper = User::factory()->create(['role' => Role::User]);
        $this->package = Package::factory()->shipped()->for(Shipment::factory())->create([
            'carrier' => 'USPS',
            'shipped_by_user_id' => $this->shipper->id,
        ]);
    });

    afterEach(function (): void {
        app(CarrierRegistry::class)->reset();
    });

    it('does not let a user void a label someone else shipped from the packages table', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::User]));

        $component = Livewire::test(ListPackages::class)->assertActionExists(TestAction::make('void')->table($this->package));

        try {
            $component->callAction(TestAction::make('void')->table($this->package));
        } catch (ExpectationFailedException) {
            // Filament will not call an action it hides from this user.
        }

        expect($this->package->fresh()->status)->toBe(PackageStatus::Shipped);
    });

    it('hides the void action from a user who did not ship the package', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::User]));

        Livewire::test(ListPackages::class)->assertActionHidden(TestAction::make('void')->table($this->package));
        Livewire::test(ViewPackage::class, ['record' => $this->package->id])->assertActionHidden('void');
    });

    it('does not let a user void a label someone else shipped from View Package', function (): void {
        $this->actingAs(User::factory()->create(['role' => Role::User]));

        $component = Livewire::test(ViewPackage::class, ['record' => $this->package->id])->assertActionExists('void');

        try {
            $component->callAction('void');
        } catch (ExpectationFailedException) {
            // Filament will not call an action it hides from this user.
        }

        expect($this->package->fresh()->status)->toBe(PackageStatus::Shipped);
    });

    it('lets the user who shipped the package void its label', function (): void {
        $this->actingAs($this->shipper);

        Livewire::test(ListPackages::class)->callAction(TestAction::make('void')->table($this->package));

        expect($this->package->fresh()->status)->toBe(PackageStatus::Unshipped);
    });

    it('lets a :dataset void a label someone else shipped', function (Role $role): void {
        $this->actingAs(User::factory()->create(['role' => $role]));

        Livewire::test(ViewPackage::class, ['record' => $this->package->id])->callAction('void');

        expect($this->package->fresh()->status)->toBe(PackageStatus::Unshipped);
    })->with(['Manager' => Role::Manager, 'Admin' => Role::Admin]);
});
