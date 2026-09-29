<?php

use App\Contracts\PackageDraftWorkflow;
use App\DataTransferObjects\PackageDrafts\BatchPackageDraftInput;
use App\Enums\PackageDraftState;
use App\Enums\PackageStatus;
use App\Enums\PickingStatus;
use App\Enums\Role;
use App\Exceptions\PackageDraftIncompleteException;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Models\BoxSize;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\SettingsService;
use Livewire\Livewire;

/**
 * @param  array<string, mixed>  $attributes
 */
function draftStatePackage(Shipment $shipment, array $attributes = []): Package
{
    return Package::factory()->for($shipment)->create([
        'status' => PackageStatus::Unshipped,
        'box_size_id' => null,
        'weight' => null,
        'height' => null,
        'width' => null,
        'length' => null,
        'tracking_number' => null,
        'carrier' => null,
        ...$attributes,
    ]);
}

function draftStateMeasured(): array
{
    return ['weight' => 1.5, 'height' => 6, 'width' => 8, 'length' => 10];
}

function draftStatePack(Package $package, ShipmentItem $item, int $quantity): void
{
    PackageItem::create([
        'package_id' => $package->id,
        'shipment_item_id' => $item->id,
        'product_id' => $item->product_id,
        'quantity' => $quantity,
    ]);
}

/**
 * Whether the purchase would accept this package: the rule the SQL restates.
 */
function draftStatePurchaseAccepts(Package $package): bool
{
    if ($package->shipment?->isBlockedByPicking()) {
        return false;
    }

    try {
        app(PackageDraftWorkflow::class)->assertPackageReadyToShip($package);

        return true;
    } catch (PackageDraftIncompleteException) {
        return false;
    }
}

it('computes the draft state the purchase readiness rule would', function (Closure $setUp, PackageDraftState $expected): void {
    $package = $setUp();

    $computed = Package::withDraftState()->whereKey($package->id)->sole()->getAttribute('draft_state');

    expect($computed)->toBe($expected->value)
        ->and(draftStatePurchaseAccepts($package->fresh()))->toBe($expected === PackageDraftState::Ready)
        ->and(Package::whereDraftState($expected)->pluck('id')->all())->toBe([$package->id]);
})->with([
    'nothing at all' => [fn (): Package => draftStatePackage(Shipment::factory()->create()), PackageDraftState::Empty],
    'lines left at zero packed' => [function (): Package {
        $shipment = Shipment::factory()->create();
        $package = draftStatePackage($shipment);
        draftStatePack($package, ShipmentItem::factory()->for($shipment)->create(['quantity' => 1]), 0);

        return $package;
    }, PackageDraftState::Empty],
    'a box and nothing else' => [fn (): Package => draftStatePackage(Shipment::factory()->create(), ['box_size_id' => BoxSize::factory()]), PackageDraftState::Packing],
    'a weight and no size' => [fn (): Package => draftStatePackage(Shipment::factory()->create(), ['weight' => 2]), PackageDraftState::Packing],
    'measured with items still to pack' => [function (): Package {
        $shipment = Shipment::factory()->create();
        $package = draftStatePackage($shipment, draftStateMeasured());
        draftStatePack($package, ShipmentItem::factory()->for($shipment)->create(['quantity' => 2]), 1);

        return $package;
    }, PackageDraftState::Packing],
    'measured and over-packed' => [function (): Package {
        $shipment = Shipment::factory()->create();
        $package = draftStatePackage($shipment, draftStateMeasured());
        draftStatePack($package, ShipmentItem::factory()->for($shipment)->create(['quantity' => 1]), 2);

        return $package;
    }, PackageDraftState::Packing],
    'measured and fully packed' => [function (): Package {
        $shipment = Shipment::factory()->create();
        $package = draftStatePackage($shipment, draftStateMeasured());
        draftStatePack($package, ShipmentItem::factory()->for($shipment)->create(['quantity' => 2]), 2);

        return $package;
    }, PackageDraftState::Ready],
    'measured with no shipment items' => [fn (): Package => draftStatePackage(Shipment::factory()->create(), draftStateMeasured()), PackageDraftState::Ready],
    'measured, items unpacked, packing validation off' => [function (): Package {
        app(SettingsService::class)->set('packing_validation_enabled', false);
        $shipment = Shipment::factory()->create();
        ShipmentItem::factory()->for($shipment)->create(['quantity' => 2]);

        return draftStatePackage($shipment, draftStateMeasured());
    }, PackageDraftState::Ready],
    'fully packed but not picked' => [function (): Package {
        app(SettingsService::class)->set('picking_enabled', true);
        app(SettingsService::class)->set('require_picking_before_shipping', true);

        return draftStatePackage(Shipment::factory()->create(['picking_status' => PickingStatus::Pending]), draftStateMeasured());
    }, PackageDraftState::Packing],
    'fully packed and picked' => [function (): Package {
        app(SettingsService::class)->set('picking_enabled', true);
        app(SettingsService::class)->set('require_picking_before_shipping', true);

        return draftStatePackage(Shipment::factory()->create(['picking_status' => PickingStatus::Picked]), draftStateMeasured());
    }, PackageDraftState::Ready],
]);

it('gives a shipped package no draft state', function (): void {
    $package = Package::factory()->shipped()->create();

    expect(Package::withDraftState()->whereKey($package->id)->sole()->getAttribute('draft_state'))->toBeNull();

    foreach (PackageDraftState::cases() as $state) {
        expect(Package::whereDraftState($state)->exists())->toBeFalse();
    }
});

it('shows and filters packages by draft state in the Packages list', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
    $empty = draftStatePackage(Shipment::factory()->create());
    $packing = draftStatePackage(Shipment::factory()->create(), ['box_size_id' => BoxSize::factory()]);
    $ready = draftStatePackage(Shipment::factory()->create(), draftStateMeasured());
    $shipped = Package::factory()->shipped()->create();

    Livewire::test(ListPackages::class)
        ->assertTableColumnStateSet('status', PackageDraftState::Empty, $empty)
        ->assertTableColumnStateSet('status', PackageDraftState::Packing, $packing)
        ->assertTableColumnStateSet('status', PackageDraftState::Ready, $ready)
        ->assertTableColumnStateSet('status', PackageStatus::Shipped, $shipped)
        ->filterTable('draft_state', PackageDraftState::Empty->value)
        ->assertCanSeeTableRecords([$empty])
        ->assertCanNotSeeTableRecords([$packing, $ready, $shipped])
        ->filterTable('draft_state', PackageDraftState::Ready->value)
        ->assertCanSeeTableRecords([$ready])
        ->assertCanNotSeeTableRecords([$empty, $packing, $shipped]);
});

it('batch ships an empty draft\'s zero-packed lines away rather than beside its own', function (): void {
    $shipment = Shipment::factory()->create();
    $item = ShipmentItem::factory()->for($shipment)->create(['quantity' => 2]);
    $empty = draftStatePackage($shipment);
    draftStatePack($empty, $item, 0);

    $ready = app(PackageDraftWorkflow::class)->createBatchReadyDraft(
        $shipment,
        new BatchPackageDraftInput(BoxSize::factory()->create()),
    );

    expect($ready->package->id)->toBe($empty->id)
        ->and($ready->package->packageItems)->toHaveCount(1)
        ->and($ready->package->packageItems->sole()->quantity)->toBe(2);
});
