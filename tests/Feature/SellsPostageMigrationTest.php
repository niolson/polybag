<?php

use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| ADR-0006 decision 6, 2026-10-26 amendment
|--------------------------------------------------------------------------
|
| A connection that sold postage to a packer, or to a packer and automation,
| sells postage. One that did not sell, or whose driver sells none, does not.
|
*/

it('turns every selling postage setting into sells postage', function (): void {
    $migration = require database_path('migrations/2026_10_26_234113_replace_postage_setting_with_sells_postage_on_data_sources_table.php');
    $migration->down();

    $ids = collect([
        'none' => [ShopifySource::class, 'none'],
        'packer' => [AmazonSource::class, 'packer'],
        'automation' => [ShopifySource::class, 'automation'],
        'database' => [DatabaseSource::class, null],
    ])->map(fn (array $row, string $name): int => DB::table('data_sources')->insertGetId([
        'name' => $name,
        'source_type' => $row[0],
        'postage_setting' => $row[1],
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    $migration->up();

    expect(Schema::hasColumn('data_sources', 'postage_setting'))->toBeFalse()
        ->and($ids->map(fn (int $id): bool => (bool) DB::table('data_sources')->where('id', $id)->value('sells_postage'))->all())
        ->toBe(['none' => false, 'packer' => true, 'automation' => true, 'database' => false]);
});
