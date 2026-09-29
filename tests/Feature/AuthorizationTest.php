<?php

use App\Contracts\DirectCarrierAdapter;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Pages\EndOfDay;
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
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\PackageResource\Pages\ViewPackage;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ShipmentResource\Pages\CreateShipment;
use App\Filament\Resources\ShipmentResource\Pages\EditShipment;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Filament\Resources\ShippingMethodResource\Pages\ListShippingMethods;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

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

    // Resources NOT accessible to manager
    it('can create shipments', function (): void {
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

        try {
            Livewire::test(EditCarrierAccount::class, ['record' => $this->account->id])
                ->fillForm(['ups_account_number' => 'Z9Y8X7'])
                ->call('save');
        } catch (Throwable) {
            // A refusal by exception is also a pass.
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

        try {
            Livewire::test(ListPackages::class)->callAction(TestAction::make('void')->table($this->package));
        } catch (Throwable) {
            // A refusal by exception is also a pass.
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

        try {
            Livewire::test(ViewPackage::class, ['record' => $this->package->id])->callAction('void');
        } catch (Throwable) {
            // A refusal by exception is also a pass.
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
