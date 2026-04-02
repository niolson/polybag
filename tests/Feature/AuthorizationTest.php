<?php

use App\Enums\Role;
use App\Filament\Pages\EndOfDay;
use App\Filament\Pages\Settings;
use App\Filament\Pages\UnmappedShippingReferences;
use App\Filament\Resources\BoxSizeResource\Pages\ListBoxSizes;
use App\Filament\Resources\Carriers\Pages\ListCarriers;
use App\Filament\Resources\CarrierServiceResource\Pages\ListCarrierServices;
use App\Filament\Resources\ChannelResource\Pages\ListChannels;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ShipmentResource\Pages\CreateShipment;
use App\Filament\Resources\ShipmentResource\Pages\EditShipment;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Filament\Resources\ShippingMethodResource\Pages\ListShippingMethods;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Shipment;
use App\Models\User;
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

    it('can access end of day page', function (): void {
        Livewire::test(EndOfDay::class)->assertSuccessful();
    });
});
