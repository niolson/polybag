<?php

use App\Enums\PostageSource;
use App\Enums\Role;
use App\Filament\Resources\PackageResource\Pages\EditPackage;
use App\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use App\Filament\Resources\ShipmentResource\RelationManagers\PackagesRelationManager;
use App\Models\DataSource;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('keeps no evidence, method or ruleset version beside the service', function (): void {
    foreach (['packages', 'package_labels'] as $table) {
        expect(Schema::hasColumns($table, ['service']))->toBeTrue()
            ->and(Schema::hasColumn($table, 'service_evidence'))->toBeFalse()
            ->and(Schema::hasColumn($table, 'service_inference_method'))->toBeFalse()
            ->and(Schema::hasColumn($table, 'service_ruleset_version'))->toBeFalse();
    }
});

it('reads a service as inferred only when the postage came from Shopify', function (): void {
    $direct = Package::factory()->shipped()->create(['service' => 'Priority Mail']);
    $shopify = Package::factory()->shipped()->create([
        'service' => 'Priority Mail',
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => DataSource::factory()->shopify()->create()->id,
    ]);

    expect($direct->serviceIsInferred())->toBeFalse()
        ->and($direct->confirmedService())->toBe('Priority Mail')
        ->and($shopify->serviceIsInferred())->toBeTrue()
        ->and($shopify->confirmedService())->toBeNull();
});

it('shows the service read-only on the Package edit form, marking an inferred one', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'Priority Mail',
        'postage_source' => PostageSource::PostageDataSource,
        'postage_data_source_id' => DataSource::factory()->shopify()->create()->id,
    ]);

    Livewire::test(EditPackage::class, ['record' => $package->id])
        ->assertSee('Priority Mail (inferred)');
});

it('lists the service on the Shipment\'s Packages tab instead of an empty field', function (): void {
    $package = Package::factory()->shipped()->create(['service' => 'Priority Mail']);

    Livewire::test(PackagesRelationManager::class, [
        'ownerRecord' => $package->shipment,
        'pageClass' => ViewShipment::class,
    ])->assertTableColumnStateSet('service', 'Priority Mail', $package);
});
