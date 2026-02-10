<?php

use App\Filament\Pages\UpdateWeight;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('renders the page for authorized users', function (): void {
    Livewire::test(UpdateWeight::class)
        ->assertSuccessful();
});

it('denies access to guests', function (): void {
    auth()->logout();

    $this->get(UpdateWeight::getUrl())
        ->assertRedirect();
});

it('lookupProduct finds product by SKU', function (): void {
    $product = Product::factory()->create(['sku' => 'TEST-SKU-001']);

    Livewire::test(UpdateWeight::class)
        ->call('lookupProduct', 'TEST-SKU-001')
        ->assertSet('currentProduct.id', $product->id)
        ->assertNotNotified();
});

it('lookupProduct finds product by barcode', function (): void {
    $product = Product::factory()->create(['barcode' => '1234567890123']);

    Livewire::test(UpdateWeight::class)
        ->call('lookupProduct', '1234567890123')
        ->assertSet('currentProduct.id', $product->id)
        ->assertNotNotified();
});

it('lookupProduct shows warning when product not found', function (): void {
    Livewire::test(UpdateWeight::class)
        ->call('lookupProduct', 'NONEXISTENT')
        ->assertSet('currentProduct', null)
        ->assertNotified();
});

it('lookupProduct clears product when barcode is empty', function (): void {
    $product = Product::factory()->create(['sku' => 'TEST-SKU']);

    Livewire::test(UpdateWeight::class)
        ->call('lookupProduct', 'TEST-SKU')
        ->assertSet('currentProduct.id', $product->id)
        ->call('lookupProduct', null)
        ->assertSet('currentProduct', null);
});

it('update saves new weight to product', function (): void {
    $product = Product::factory()->create(['sku' => 'WEIGHT-TEST', 'weight' => 1.50]);

    Livewire::test(UpdateWeight::class)
        ->set('data.barcode', 'WEIGHT-TEST')
        ->set('data.weight', 2.75)
        ->call('update')
        ->assertNotified();

    expect($product->fresh()->weight)->toBe('2.75');
});

it('update shows error when product not found', function (): void {
    Livewire::test(UpdateWeight::class)
        ->set('data.barcode', 'NONEXISTENT')
        ->set('data.weight', 1.00)
        ->call('update')
        ->assertNotified();
});

it('update adds entry to recentUpdates array', function (): void {
    $product = Product::factory()->create(['sku' => 'RECENT-TEST', 'name' => 'Test Product', 'weight' => 1.00]);

    Livewire::test(UpdateWeight::class)
        ->set('data.barcode', 'RECENT-TEST')
        ->set('data.weight', 2.00)
        ->call('update')
        ->assertSet('recentUpdates', fn ($updates) => count($updates) === 1
            && $updates[0]['sku'] === 'RECENT-TEST'
            && $updates[0]['old_weight'] === '1.00'
            && $updates[0]['new_weight'] == 2.00
        );
});

it('update resets form after success', function (): void {
    Product::factory()->create(['sku' => 'RESET-TEST', 'weight' => 1.00]);

    Livewire::test(UpdateWeight::class)
        ->set('data.barcode', 'RESET-TEST')
        ->set('data.weight', 3.00)
        ->call('update')
        ->assertSet('currentProduct', null);
});
