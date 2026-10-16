<?php

use App\Filament\Resources\PackageResource;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Filament\Resources\ShipmentResource\RelationManagers\PackagesRelationManager;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('offers no way to create a Package outside packing', function (): void {
    expect(PackageResource::hasPage('create'))->toBeFalse()
        ->and(PackageResource::canCreate())->toBeFalse()
        ->and(collect(Route::getRoutes()->getRoutes())->contains(fn ($route): bool => $route->uri() === 'packages/create'))->toBeFalse();

    Livewire::test(ListPackages::class)->assertActionDoesNotExist('create');

    $shipment = Shipment::factory()->create();

    Livewire::test(PackagesRelationManager::class, ['ownerRecord' => $shipment, 'pageClass' => ViewShipment::class])
        ->assertTableActionDoesNotExist('create');
});
