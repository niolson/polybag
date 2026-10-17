<?php

use App\Enums\HazmatClass;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Product;
use App\Models\SpecialService;
use App\Models\User;
use Database\Seeders\SpecialServiceSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
    (new SpecialServiceSeeder)->run();
    SpecialService::whereIn('code', ['dry_ice', 'cremated_remains'])->update(['active' => false]);
    SpecialService::whereIn('code', ['lithium_battery_in_equipment', 'lithium_battery_standalone', 'lithium_battery_ground_only'])->update(['active' => true]);
});

it('offers only hazmat classes whose special service is active', function (): void {
    expect(array_keys(ProductResource::hazmatClassOptions()))->toBe([
        HazmatClass::LithiumBatteryInEquipment->value,
        HazmatClass::LithiumBatteryStandalone->value,
        HazmatClass::LithiumBatteryGroundOnly->value,
    ]);
});

it('refuses an inactive hazmat class on a new product', function (): void {
    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Frozen Steaks',
            'sku' => 'STEAK-1',
            'hazmat_class' => HazmatClass::DryIce->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['hazmat_class']);
});

it('keeps a product\'s inactive hazmat class listed while editing it', function (): void {
    $product = Product::factory()->create(['hazmat_class' => HazmatClass::DryIce]);

    expect(ProductResource::hazmatClassOptions($product->hazmat_class))->toHaveKey(HazmatClass::DryIce->value);

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->assertSchemaStateSet(['hazmat_class' => HazmatClass::DryIce->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->hazmat_class)->toBe(HazmatClass::DryIce);
});
