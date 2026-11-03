<?php

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Client;
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

it('saves a customs value from the product form, shown in its client\'s currency', function (): void {
    $product = Product::factory()->create(['customs_value' => null]);
    $product->client->update(['currency' => 'CAD']);

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->assertSee('CAD')
        ->fillForm(['customs_value' => 24.5])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->customs_value)->toBe('24.50');
});

it('refuses a negative customs value', function (): void {
    $product = Product::factory()->create();

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->fillForm(['customs_value' => -1])
        ->call('save')
        ->assertHasFormErrors(['customs_value']);
});

it('renders the create form with no default client, labelling the customs value USD', function (): void {
    Client::query()->update(['is_default' => false]);

    Livewire::test(CreateProduct::class)
        ->assertSuccessful()
        ->assertSee('USD');
});
