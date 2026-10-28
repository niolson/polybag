<?php

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Client;
use App\Models\PackageItem;
use App\Models\Product;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\ClientContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| product-catalog-sync/01
|--------------------------------------------------------------------------
|
| One Product per SKU in a client. The migration merges existing duplicates
| into the lowest id before the unique index goes on.
|
*/

function productSkuMigration(): object
{
    return require database_path('migrations/2026_10_28_050738_make_products_unique_by_client_and_sku.php');
}

/**
 * A products row written past the model, which would turn an empty SKU into NULL.
 *
 * @param  array<string, mixed>  $values
 */
function insertRawProduct(array $values): int
{
    return DB::table('products')->insertGetId([
        'client_id' => app(ClientContext::class)->id(),
        'name' => 'Product',
        'created_at' => now(),
        'updated_at' => now(),
        ...$values,
    ]);
}

// --- the index --------------------------------------------------------------

it('refuses a second product with the same SKU in a client', function (): void {
    Product::factory()->create(['sku' => 'MUG-1']);

    expect(fn () => Product::factory()->create(['sku' => 'MUG-1']))
        ->toThrow(UniqueConstraintViolationException::class);
})->group('mysql');

it('allows the same SKU in another client, and any number of NULL SKUs', function (): void {
    Product::factory()->create(['sku' => 'MUG-1']);
    Product::factory()->create(['sku' => 'MUG-1', 'client_id' => Client::factory()->create()->id]);
    Product::factory()->count(3)->create(['sku' => null]);

    expect(Product::where('sku', 'MUG-1')->count())->toBe(2)
        ->and(Product::whereNull('sku')->count())->toBe(3);
})->group('mysql');

it('stores a blank SKU as NULL', function (?string $sku): void {
    Product::factory()->count(2)->create(['sku' => $sku]);

    expect(Product::whereNull('sku')->count())->toBe(2);
})->with([
    'empty' => [''],
    'spaces' => ['   '],
])->group('mysql');

// --- the migration ----------------------------------------------------------

it('merges duplicate SKUs into the lowest id, filling its blanks and repointing items', function (): void {
    $migration = productSkuMigration();
    $migration->down();

    $survivorId = insertRawProduct(['sku' => 'DUP-1', 'name' => 'First', 'weight' => null, 'barcode' => null, 'hs_tariff_number' => '']);
    $secondId = insertRawProduct(['sku' => 'DUP-1', 'name' => 'Second', 'weight' => 1.25, 'barcode' => null, 'hs_tariff_number' => '610910']);
    $thirdId = insertRawProduct(['sku' => 'DUP-1', 'name' => 'Third', 'weight' => 9.99, 'barcode' => '0123456789012']);
    $otherClientId = insertRawProduct(['sku' => 'DUP-1', 'client_id' => Client::factory()->create()->id]);

    $shipmentItem = ShipmentItem::factory()->create(['product_id' => $secondId]);
    $packageItem = PackageItem::factory()->create(['product_id' => $thirdId, 'shipment_item_id' => $shipmentItem->id]);

    $migration->up();

    $survivor = DB::table('products')->find($survivorId);

    expect(DB::table('products')->whereIn('id', [$secondId, $thirdId])->exists())->toBeFalse()
        ->and(DB::table('products')->where('id', $otherClientId)->exists())->toBeTrue()
        ->and($survivor->name)->toBe('First')
        ->and((float) $survivor->weight)->toBe(1.25)
        ->and($survivor->barcode)->toBe('0123456789012')
        ->and($survivor->hs_tariff_number)->toBe('610910')
        ->and($shipmentItem->refresh()->product_id)->toBe($survivorId)
        ->and($packageItem->refresh()->product_id)->toBe($survivorId);
})->group('mysql');

it('keeps an alcohol or media declaration any duplicate made', function (): void {
    $migration = productSkuMigration();
    $migration->down();

    $survivorId = insertRawProduct(['sku' => 'WINE-1', 'contains_alcohol' => false, 'is_media' => false]);
    insertRawProduct(['sku' => 'WINE-1', 'contains_alcohol' => true, 'is_media' => false]);
    insertRawProduct(['sku' => 'WINE-1', 'contains_alcohol' => false, 'is_media' => true]);
    $plainId = insertRawProduct(['sku' => 'PLAIN-1', 'contains_alcohol' => false, 'is_media' => false]);
    insertRawProduct(['sku' => 'PLAIN-1', 'contains_alcohol' => false, 'is_media' => false]);

    $migration->up();

    $survivor = Product::findOrFail($survivorId);
    $plain = Product::findOrFail($plainId);

    expect($survivor->contains_alcohol)->toBeTrue()
        ->and($survivor->is_media)->toBeTrue()
        ->and($plain->contains_alcohol)->toBeFalse()
        ->and($plain->is_media)->toBeFalse();
})->group('mysql');

it('keeps NULL and empty SKUs as separate products with their own items', function (): void {
    $migration = productSkuMigration();
    $migration->down();

    $ids = [
        insertRawProduct(['sku' => null]),
        insertRawProduct(['sku' => null]),
        insertRawProduct(['sku' => '']),
        insertRawProduct(['sku' => '']),
    ];
    $items = collect($ids)->map(fn (int $id): ShipmentItem => ShipmentItem::factory()->create(['product_id' => $id]));

    $migration->up();

    expect(DB::table('products')->whereIn('id', $ids)->whereNull('sku')->count())->toBe(4)
        ->and($items->map(fn (ShipmentItem $item): int => $item->refresh()->product_id)->all())->toBe($ids);
})->group('mysql');

it('merges SKUs that MySQL compares as equal before adding the index', function (): void {
    $migration = productSkuMigration();
    $migration->down();

    $survivorId = insertRawProduct(['sku' => 'abc']);
    $caseId = insertRawProduct(['sku' => 'ABC']);
    $paddedId = insertRawProduct(['sku' => 'abc ']);
    $item = ShipmentItem::factory()->create(['product_id' => $paddedId]);

    $migration->up();

    expect(DB::table('products')->whereIn('id', [$survivorId, $caseId, $paddedId])->pluck('id')->all())->toBe([$survivorId])
        ->and($item->refresh()->product_id)->toBe($survivorId);
})->skip(fn (): bool => DB::getDriverName() !== 'mysql', 'MySQL collation only')->group('mysql');

// --- the Products form ------------------------------------------------------

it('shows a validation error for a SKU already used in the client', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    Product::factory()->create(['sku' => 'MUG-1']);

    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Another Mug', 'sku' => 'MUG-1'])
        ->call('create')
        ->assertHasFormErrors(['sku' => 'unique']);

    expect(Product::where('sku', 'MUG-1')->count())->toBe(1);
});

it('accepts a SKU another client already uses', function (): void {
    config()->set('features.multi_client_enabled', true);
    $this->actingAs(User::factory()->admin()->create());
    Product::factory()->create(['sku' => 'MUG-1']);
    $other = Client::factory()->create();

    Livewire::test(CreateProduct::class)
        ->fillForm(['client_id' => $other->id, 'name' => 'Their Mug', 'sku' => 'MUG-1'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::where('sku', 'MUG-1')->pluck('client_id')->sort()->values()->all())
        ->toBe(collect([app(ClientContext::class)->id(), $other->id])->sort()->values()->all());
});

it('saves a product under its own SKU', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $product = Product::factory()->create(['sku' => 'MUG-1']);

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->fillForm(['name' => 'Renamed Mug'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->name)->toBe('Renamed Mug');
});

it('refuses to move a product into a client that already has its SKU', function (): void {
    config()->set('features.multi_client_enabled', true);
    $this->actingAs(User::factory()->admin()->create());
    $other = Client::factory()->create();
    Product::factory()->create(['sku' => 'MUG-1', 'client_id' => $other->id]);
    $product = Product::factory()->create(['sku' => 'MUG-1']);

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->fillForm(['client_id' => $other->id])
        ->call('save')
        ->assertHasFormErrors(['sku' => 'unique']);

    expect($product->fresh()->client_id)->toBe(app(ClientContext::class)->id());
});
