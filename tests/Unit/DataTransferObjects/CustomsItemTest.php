<?php

use App\DataTransferObjects\Shipping\CustomsItem;
use App\Models\PackageItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $productAttributes
 */
function customsItemFor(array $productAttributes): CustomsItem
{
    $packageItem = PackageItem::factory()->create([
        'product_id' => Product::factory()->create($productAttributes)->id,
    ]);

    return CustomsItem::fromPackageItem($packageItem->fresh());
}

it('resolves all three EU product identifiers from the product', function (): void {
    $item = customsItemFor([
        'sku' => 'SKU-12345',
        'manufacturer_part_number' => 'MFG-67890',
        'gtin' => '4006381333931',
        'barcode' => '036000291452',
    ]);

    expect($item->merchantProductId)->toBe('SKU-12345')
        ->and($item->manufacturerProductId)->toBe('MFG-67890')
        ->and($item->standardProductId)->toBe('4006381333931');
});

it('falls back to a scan barcode that is a UPC when the product has no GTIN', function (): void {
    expect(customsItemFor(['gtin' => null, 'barcode' => '036000291452'])->standardProductId)->toBe('036000291452');
});

it('never declares a Code 128 scan barcode as a standard identifier', function (): void {
    expect(customsItemFor(['gtin' => null, 'barcode' => 'WH-000123'])->standardProductId)->toBeNull();
});

it('falls back to the barcode when the stored GTIN has a bad check digit', function (): void {
    expect(customsItemFor(['gtin' => '036000291453', 'barcode' => '4006381333931'])->standardProductId)->toBe('4006381333931');
});

it('leaves an identifier null when the product has nothing to say', function (): void {
    $item = customsItemFor(['sku' => null, 'manufacturer_part_number' => null, 'gtin' => null, 'barcode' => null]);

    expect($item->merchantProductId)->toBeNull()
        ->and($item->manufacturerProductId)->toBeNull()
        ->and($item->standardProductId)->toBeNull();
});

it('changes only the weight in withWeight', function (): void {
    $item = new CustomsItem(
        description: 'Mug',
        quantity: 2,
        unitValue: 12.0,
        weight: 0.4,
        hsTariffNumber: '6912.00',
        countryOfOrigin: 'CN',
        merchantProductId: 'SKU-1',
        manufacturerProductId: 'MFG-1',
        standardProductId: '4006381333931',
    );

    expect(get_object_vars($item->withWeight(0.25)))->toBe(array_replace(get_object_vars($item), ['weight' => 0.25]));
});
