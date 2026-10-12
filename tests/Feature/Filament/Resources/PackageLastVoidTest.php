<?php

use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Enums\VoidReason;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Filament\Resources\ShipmentResource\RelationManagers\PackagesRelationManager;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageLabel;
use App\Models\Shipment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

/**
 * A package returned to unshipped by a void with the given reason.
 */
function packageVoidedBy(VoidReason $reason, ?Shipment $shipment = null): Package
{
    $package = Package::factory()
        ->for($shipment ?? Shipment::factory())
        ->create(['status' => PackageStatus::Unshipped]);

    PackageLabel::factory()->for($package)->create([
        'purchased_at' => now()->subDay(),
        'voided_at' => now()->subHours(2),
        'void_reason' => $reason,
    ]);

    return $package;
}

/**
 * A package that was voided by an operator and then bought again.
 */
function packageBoughtAgainAfterVoid(?Shipment $shipment = null): Package
{
    $package = Package::factory()->shipped()->for($shipment ?? Shipment::factory())->create();

    PackageLabel::factory()->for($package)->create([
        'purchased_at' => now()->subDays(2),
        'voided_at' => now()->subDays(2)->addHour(),
        'void_reason' => VoidReason::VoidedUpstream,
    ]);

    return $package;
}

// --- Packages list -----------------------------------------------------------------

it('shows who voided the label of a package unshipped after a void', function (VoidReason $reason): void {
    $package = packageVoidedBy($reason);

    Livewire::test(ListPackages::class)
        ->assertCanSeeTableRecords([$package])
        ->assertTableColumnFormattedStateSet('last_void_reason', $reason->getLabel(), $package);
})->with(VoidReason::cases());

it('shows no last void for a package never voided or bought again since', function (): void {
    $neverShipped = Package::factory()->create(['status' => PackageStatus::Unshipped]);
    $shipped = Package::factory()->shipped()->create();
    $boughtAgain = packageBoughtAgainAfterVoid();

    Livewire::test(ListPackages::class)
        ->assertTableColumnStateSet('last_void_reason', null, $neverShipped)
        ->assertTableColumnStateSet('last_void_reason', null, $shipped)
        ->assertTableColumnStateSet('last_void_reason', null, $boughtAgain);
});

it('shows the most recent of several voids', function (): void {
    $package = packageVoidedBy(VoidReason::VoidedUpstream);

    // An older void, written later, so neither id order alone nor creation
    // order decides which void is the latest.
    PackageLabel::factory()->for($package)->create([
        'purchased_at' => now()->subDays(3),
        'voided_at' => now()->subDays(3)->addHour(),
        'void_reason' => VoidReason::Recorded,
    ]);

    Livewire::test(ListPackages::class)
        ->assertTableColumnFormattedStateSet('last_void_reason', VoidReason::VoidedUpstream->getLabel(), $package);
});

it('filters the list by who voided the last label', function (VoidReason $reason): void {
    $packages = collect(VoidReason::cases())
        ->mapWithKeys(fn (VoidReason $case): array => [$case->value => packageVoidedBy($case)]);
    $neverVoided = Package::factory()->shipped()->create();
    $boughtAgain = packageBoughtAgainAfterVoid();

    Livewire::test(ListPackages::class)
        ->filterTable('last_void_reason', $reason->value)
        ->assertCanSeeTableRecords([$packages[$reason->value]])
        ->assertCanNotSeeTableRecords([
            ...$packages->except($reason->value)->values(),
            $neverVoided,
            $boughtAgain,
        ]);
})->with(VoidReason::cases());

it('lists every package when the last void filter is cleared', function (): void {
    $voided = packageVoidedBy(VoidReason::Operator);
    $neverVoided = Package::factory()->shipped()->create();

    Livewire::test(ListPackages::class)
        ->filterTable('last_void_reason', VoidReason::Operator->value)
        ->resetTableFilters()
        ->assertCanSeeTableRecords([$voided, $neverVoided]);
});

// --- Shipment view ------------------------------------------------------------------

it('shows who voided each package label on the shipment view', function (VoidReason $reason): void {
    $shipment = Shipment::factory()->create();
    $voided = packageVoidedBy($reason, $shipment);
    $boughtAgain = packageBoughtAgainAfterVoid($shipment);
    $neverVoided = Package::factory()->for($shipment)->create(['status' => PackageStatus::Unshipped]);

    Livewire::test(PackagesRelationManager::class, [
        'ownerRecord' => $shipment,
        'pageClass' => ViewShipment::class,
    ])
        ->assertCanSeeTableRecords([$voided, $boughtAgain, $neverVoided])
        ->assertTableColumnFormattedStateSet('last_void_reason', $reason->getLabel(), $voided)
        ->assertTableColumnStateSet('last_void_reason', null, $boughtAgain)
        ->assertTableColumnStateSet('last_void_reason', null, $neverVoided)
        ->assertSee($reason->getLabel())
        ->assertSee($voided->latestVoidedLabel->voided_at->tz(Location::timezone())->format('M j, Y g:i A'));
})->with(VoidReason::cases());
