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

it('leaves the flag unchanged and warns on an unrecognised value', function (mixed $value): void {
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
