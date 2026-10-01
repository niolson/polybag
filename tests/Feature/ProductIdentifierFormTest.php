<?php

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('saves a manufacturer part number and GTIN from the product form', function (): void {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Ceramic Mug',
            'sku' => 'MUG-001',
            'manufacturer_part_number' => 'MFG-67890',
            'gtin' => '036000291452',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('sku', 'MUG-001')->sole();

    expect($product->manufacturer_part_number)->toBe('MFG-67890')
        ->and($product->gtin)->toBe('036000291452');
});

it('refuses a GTIN whose check digit is wrong, and says so', function (): void {
    $product = Product::factory()->create();

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->fillForm(['gtin' => '036000291453'])
        ->call('save')
        ->assertHasFormErrors(['gtin'])
        ->assertSee('valid check digit');

    expect($product->fresh()->gtin)->toBe($product->gtin);
});

it('lets the GTIN be left blank', function (): void {
    $product = Product::factory()->create();

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->fillForm(['gtin' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->gtin)->toBeNull();
});
