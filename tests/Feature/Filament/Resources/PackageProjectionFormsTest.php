<?php

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Resources\PackageResource\Pages\EditPackage;
use App\Filament\Resources\ShipmentResource\Pages\EditShipment;
use App\Filament\Resources\ShipmentResource\RelationManagers\PackagesRelationManager;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

// The tracking number, cost and status on a package are the projection of its
// active label and only the label writers set them (ADR-0004 decision 3). A
// manager who could hand-edit them could flip a shipped package to unshipped
// with its label still active, or ship one that has no label.

it('offers no tracking number, cost or status on the package edit form', function (): void {
    $package = Package::factory()->shipped()->create();

    Livewire::test(EditPackage::class, ['record' => $package->id])
        ->assertFormFieldDoesNotExist('tracking_number')
        ->assertFormFieldDoesNotExist('cost')
        ->assertFormFieldDoesNotExist('status')
        ->assertFormFieldExists('weight');
});

it('leaves the projection alone when the package edit form is submitted with those columns set', function (): void {
    $package = Package::factory()->shipped()->create([
        'tracking_number' => 'ORIGINAL',
        'cost' => 10.00,
    ]);

    Livewire::test(EditPackage::class, ['record' => $package->id])
        ->set('data.tracking_number', 'TAMPERED')
        ->set('data.cost', 99.99)
        ->set('data.status', PackageStatus::Unshipped->value)
        ->set('data.weight', 4.5)
        ->call('save')
        ->assertHasNoFormErrors();

    $package->refresh();

    expect($package->tracking_number)->toBe('ORIGINAL')
        ->and((float) $package->cost)->toBe(10.00)
        ->and($package->status)->toBe(PackageStatus::Shipped)
        ->and((float) $package->weight)->toBe(4.5);
});

it('offers no tracking number, cost or status on the shipment\'s package relation manager form', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->shipped()->for($shipment)->create([
        'tracking_number' => 'ORIGINAL',
        'cost' => 10.00,
    ]);

    Livewire::test(PackagesRelationManager::class, [
        'ownerRecord' => $shipment,
        'pageClass' => EditShipment::class,
    ])
        ->callAction(TestAction::make(EditAction::class)->table($package), [
            'tracking_number' => 'TAMPERED',
            'cost' => 99.99,
            'status' => PackageStatus::Unshipped->value,
            'weight' => 4.5,
        ])
        ->assertHasNoActionErrors();

    $package->refresh();

    expect($package->tracking_number)->toBe('ORIGINAL')
        ->and((float) $package->cost)->toBe(10.00)
        ->and($package->status)->toBe(PackageStatus::Shipped)
        ->and((float) $package->weight)->toBe(4.5);
});

it('refuses to bulk-delete a shipped package from the relation manager', function (): void {
    $shipment = Shipment::factory()->create();
    $shipped = Package::factory()->shipped()->for($shipment)->create();
    $unshipped = Package::factory()->for($shipment)->create();

    Livewire::test(PackagesRelationManager::class, [
        'ownerRecord' => $shipment,
        'pageClass' => EditShipment::class,
    ])
        ->selectTableRecords([$shipped, $unshipped])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified('Cannot delete packages');

    expect(Package::query()->whereKey([$shipped->id, $unshipped->id])->count())->toBe(2)
        ->and($shipped->labels()->count())->toBe(1);
});

it('still bulk-deletes unshipped packages from the relation manager', function (): void {
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->for($shipment)->create();

    Livewire::test(PackagesRelationManager::class, [
        'ownerRecord' => $shipment,
        'pageClass' => EditShipment::class,
    ])
        ->selectTableRecords([$package])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    expect(Package::query()->find($package->id))->toBeNull();
});
