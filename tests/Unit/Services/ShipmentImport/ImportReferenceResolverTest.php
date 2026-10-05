<?php

use App\Models\Product;
use App\Services\ShipmentImport\ImportReferenceResolver;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('creates a product with the media flag from a mapped column', function (mixed $value, bool $expected): void {
    app(ImportReferenceResolver::class)->productIdFor(['sku' => 'BOOK-1', 'is_media' => $value]);

    expect(Product::where('sku', 'BOOK-1')->sole()->is_media)->toBe($expected);
})->with([
    'bool true' => [true, true],
    'int 1' => [1, true],
    'string 1' => ['1', true],
    'TRUE' => ['TRUE', true],
    't' => ['t', true],
    'Yes' => ['Yes', true],
    'Y padded' => [' Y ', true],
    'on' => ['on', true],
    'bool false' => [false, false],
    'int 0' => [0, false],
    'string 0' => ['0', false],
    'false' => ['false', false],
    'f' => ['f', false],
    'No' => ['No', false],
    'n' => ['n', false],
    'off' => ['off', false],
]);

it('updates an existing product flag from a mapped column', function (): void {
    $marked = Product::factory()->media()->create(['sku' => 'WAS-MEDIA']);
    $unmarked = Product::factory()->create(['sku' => 'NOW-MEDIA']);
    $resolver = app(ImportReferenceResolver::class);

    $cleared = $resolver->productIdFor(['sku' => 'WAS-MEDIA', 'name' => $marked->name, 'is_media' => 'N']);
    $set = $resolver->productIdFor(['sku' => 'NOW-MEDIA', 'name' => $unmarked->name, 'is_media' => 'Y']);

    expect($marked->refresh()->is_media)->toBeFalse()
        ->and($unmarked->refresh()->is_media)->toBeTrue()
        ->and($cleared['updated'])->toBeTrue()
        ->and($set['updated'])->toBeTrue();
});

it('leaves a hand-set flag alone when the media field is unmapped or null', function (array $itemData): void {
    $product = Product::factory()->media()->create(['sku' => 'HAND-SET']);

    app(ImportReferenceResolver::class)->productIdFor(['sku' => 'HAND-SET', ...$itemData]);

    expect($product->refresh()->is_media)->toBeTrue();
})->with([
    'unmapped' => [[]],
    'null column' => [['is_media' => null]],
]);

it('leaves the flag unchanged and warns on an unrecognized value', function (mixed $value): void {
    $product = Product::factory()->media()->create(['sku' => 'ODD-VALUE']);
    Log::shouldReceive('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'media flag')
            && $context['sku'] === 'ODD-VALUE')
        ->once();

    $result = app(ImportReferenceResolver::class)->productIdFor(['sku' => 'ODD-VALUE', 'is_media' => $value]);

    expect($result['id'])->toBe($product->id)
        ->and($product->refresh()->is_media)->toBeTrue();
})->with([
    'word' => ['maybe'],
    'number' => [2],
    'empty string' => [''],
]);

it('passes a column mapped to is_media through a database source', function (): void {
    DB::statement('CREATE TEMPORARY TABLE ext_media_items (shipment_id VARCHAR(255), sku VARCHAR(50), media_mail CHAR(1))');
    DB::table('ext_media_items')->insert(['shipment_id' => 'A-1', 'sku' => 'BOOK-2', 'media_mail' => 'Y']);

    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'shipment_items_table' => 'ext_media_items',
        'field_mapping' => ['shipment_item' => ['sku' => 'sku', 'media_mail' => 'is_media']],
    ]);

    app(ImportReferenceResolver::class)->productIdFor($source->fetchShipmentItems('A-1')->sole());

    expect(Product::where('sku', 'BOOK-2')->sole()->is_media)->toBeTrue();
});

/**
 * Import one line of `ext_customs_items` for SKU `EU-1` through a Database source
 * that maps each customs column to its product field.
 *
 * @param  array<string, string>  $values
 */
function importCustomsColumns(array $values): Product
{
    DB::table('ext_customs_items')->delete();
    DB::table('ext_customs_items')->insert(['shipment_id' => 'A-1', 'sku' => 'EU-1'] + $values);

    $source = new DatabaseSource([
        'connection' => config('database.default'),
        'shipment_items_table' => 'ext_customs_items',
        'field_mapping' => ['shipment_item' => [
            'sku' => 'sku',
            'mpn' => 'manufacturer_part_number',
            'ean' => 'gtin',
            'tariff' => 'hs_tariff_number',
            'origin' => 'country_of_origin',
        ]],
    ]);

    app(ImportReferenceResolver::class)->productIdFor($source->fetchShipmentItems('A-1')->sole());

    return Product::where('sku', 'EU-1')->sole();
}

it('writes and then updates each mapped customs column on the product', function (): void {
    DB::statement('CREATE TEMPORARY TABLE ext_customs_items (shipment_id VARCHAR(255), sku VARCHAR(50), mpn VARCHAR(100), ean VARCHAR(14), tariff VARCHAR(20), origin CHAR(2))');

    $created = importCustomsColumns(['mpn' => 'MFG-1', 'ean' => '4006381333931', 'tariff' => '6912.00', 'origin' => 'CN']);

    expect($created->manufacturer_part_number)->toBe('MFG-1');
    expect($created->gtin)->toBe('4006381333931');
    expect($created->hs_tariff_number)->toBe('6912.00');
    expect($created->country_of_origin)->toBe('CN');

    $updated = importCustomsColumns(['mpn' => 'MFG-2', 'ean' => '036000291452', 'tariff' => '9506.11', 'origin' => 'VN']);

    expect($updated->manufacturer_part_number)->toBe('MFG-2');
    expect($updated->gtin)->toBe('036000291452');
    expect($updated->hs_tariff_number)->toBe('9506.11');
    expect($updated->country_of_origin)->toBe('VN');
});

it('keeps an existing value for a fill-only field and fills a blank one', function (string $field, string $existing, string $imported): void {
    $resolver = app(ImportReferenceResolver::class);
    $kept = Product::factory()->create(['sku' => 'HAS-VALUE', $field => $existing]);
    $blank = Product::factory()->create(['sku' => 'NO-VALUE', $field => null]);

    $resolver->productIdFor(['sku' => 'HAS-VALUE', $field => $imported, '_fill_only' => [$field]]);
    $resolver->productIdFor(['sku' => 'NO-VALUE', $field => $imported, '_fill_only' => [$field]]);

    expect($kept->refresh()->getAttribute($field))->toBe($existing)
        ->and($blank->refresh()->getAttribute($field))->toBe($imported);
})->with([
    'barcode' => ['barcode', 'MANUAL-BARCODE', '012345678905'],
    'manufacturer part number' => ['manufacturer_part_number', 'HAND-MPN', 'AMZ-MPN'],
]);

it('still overwrites fields a fill-only row does not list', function (): void {
    $product = Product::factory()->create([
        'sku' => 'MIXED',
        'name' => 'Old name',
        'manufacturer_part_number' => 'HAND-MPN',
    ]);

    app(ImportReferenceResolver::class)->productIdFor([
        'sku' => 'MIXED',
        'name' => 'New name',
        'manufacturer_part_number' => 'AMZ-MPN',
        '_fill_only' => ['manufacturer_part_number'],
    ]);

    expect($product->refresh()->name)->toBe('New name')
        ->and($product->manufacturer_part_number)->toBe('HAND-MPN');
});
