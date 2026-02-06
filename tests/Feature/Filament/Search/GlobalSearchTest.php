<?php

use App\Enums\Role;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ShipmentResource\Pages\ListShipments;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Laravel\Scout\Searchable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Admin]));
});

it('uses the Searchable trait on Shipment model', function (): void {
    expect(in_array(Searchable::class, class_uses_recursive(Shipment::class)))->toBeTrue();
});

it('uses the Searchable trait on Package model', function (): void {
    expect(in_array(Searchable::class, class_uses_recursive(Package::class)))->toBeTrue();
});

it('uses the Searchable trait on Product model', function (): void {
    expect(in_array(Searchable::class, class_uses_recursive(Product::class)))->toBeTrue();
});

it('configures Shipment searchable array with correct fields', function (): void {
    $shipment = Shipment::factory()->create([
        'shipment_reference' => 'TEST123',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'address1' => '123 Main St',
        'city' => 'Seattle',
    ]);

    $array = $shipment->toSearchableArray();

    expect($array)->toHaveKeys(['shipment_reference', 'first_name', 'last_name', 'address1', 'city'])
        ->and($array['shipment_reference'])->toBe('TEST123')
        ->and($array['first_name'])->toBe('John')
        ->and($array['last_name'])->toBe('Doe')
        ->and($array['address1'])->toBe('123 Main St')
        ->and($array['city'])->toBe('Seattle');
});

it('configures Package searchable array with correct fields', function (): void {
    $package = Package::factory()->shipped()->for(Shipment::factory())->create([
        'tracking_number' => '94001234567890',
    ]);

    $array = $package->toSearchableArray();

    expect($array)->toHaveKeys(['tracking_number'])
        ->and($array['tracking_number'])->toBe('94001234567890');
});

it('configures Product searchable array with correct fields', function (): void {
    $product = Product::factory()->create([
        'name' => 'Test Product',
        'sku' => 'SKU001',
        'barcode' => '1234567890123',
    ]);

    $array = $product->toSearchableArray();

    expect($array)->toHaveKeys(['name', 'sku', 'barcode'])
        ->and($array['name'])->toBe('Test Product')
        ->and($array['sku'])->toBe('SKU001')
        ->and($array['barcode'])->toBe('1234567890123');
});

it('can search shipments table by shipment reference', function (): void {
    $match = Shipment::factory()->create(['shipment_reference' => 'SEARCH001']);
    $noMatch = Shipment::factory()->create(['shipment_reference' => 'ZZOTHER99']);

    Livewire::test(ListShipments::class)
        ->searchTable('SEARCH001')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$noMatch]);
});

it('can search shipments table by first name', function (): void {
    $match = Shipment::factory()->create([
        'first_name' => 'Bartholomew',
        'last_name' => 'Henderson',
    ]);
    $noMatch = Shipment::factory()->create([
        'first_name' => 'Zzz',
        'last_name' => 'Zzz',
    ]);

    Livewire::test(ListShipments::class)
        ->searchTable('Bartholomew')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$noMatch]);
});

it('can search shipments table by city', function (): void {
    $match = Shipment::factory()->create(['city' => 'Xylophonia']);
    $noMatch = Shipment::factory()->create(['city' => 'Normaltown']);

    Livewire::test(ListShipments::class)
        ->searchTable('Xylophonia')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$noMatch]);
});

it('can search packages table by tracking number', function (): void {
    $match = Package::factory()->shipped()->for(Shipment::factory())->create([
        'tracking_number' => '94009876543210',
    ]);
    $noMatch = Package::factory()->shipped()->for(Shipment::factory())->create([
        'tracking_number' => '77001111111111',
    ]);

    Livewire::test(ListPackages::class)
        ->searchTable('94009876543210')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$noMatch]);
});

it('can search products table by sku', function (): void {
    $match = Product::factory()->create(['sku' => 'FINDME99']);
    $noMatch = Product::factory()->create(['sku' => 'NOTTHIS1']);

    Livewire::test(ListProducts::class)
        ->searchTable('FINDME99')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$noMatch]);
});

it('can search products table by name', function (): void {
    $match = Product::factory()->create(['name' => 'Unique Vitamin Supplement']);
    $noMatch = Product::factory()->create(['name' => 'Regular Thing']);

    Livewire::test(ListProducts::class)
        ->searchTable('Unique Vitamin')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$noMatch]);
});

it('returns empty results for non-matching search', function (): void {
    Shipment::factory()->count(3)->create();

    Livewire::test(ListShipments::class)
        ->searchTable('ZZZNONEXISTENT999')
        ->assertCountTableRecords(0);
});
