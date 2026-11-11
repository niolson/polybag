<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| amazon-buy-shipping/17 — OTDR protection moves to the Amazon row
|--------------------------------------------------------------------------
|
| A method that lets Amazon Buy Shipping sell keeps its requirement on that
| row. One that does not loses it: it could only ever hold orders for a person.
|
*/

it('moves the requirement onto the method\'s Amazon row, and drops it where there is none', function (): void {
    $migration = require database_path('migrations/2026_11_10_234907_move_otdr_protection_orders_to_shipping_method_postage_sources_table.php');
    $migration->down();

    $method = fn (string $name, ?string $otdr): int => DB::table('shipping_methods')->insertGetId([
        'name' => $name,
        'otdr_protection_orders' => $otdr,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $row = fn (int $methodId, string $kind): int => DB::table('shipping_method_postage_sources')->insertGetId([
        'shipping_method_id' => $methodId,
        'source_kind' => $kind,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $withAmazon = $method('With Amazon', '["prime","other"]');
    $amazonRow = $row($withAmazon, 'amazon');
    $directRow = $row($withAmazon, 'direct');
    $withoutAmazon = $method('Without Amazon', '["premium"]');
    $shopifyRow = $row($withoutAmazon, 'shopify');

    $migration->up();

    $stored = fn (int $id): ?array => json_decode((string) DB::table('shipping_method_postage_sources')->where('id', $id)->value('otdr_protection_orders'), true);

    expect(Schema::hasColumn('shipping_methods', 'otdr_protection_orders'))->toBeFalse()
        ->and($stored($amazonRow))->toBe(['prime', 'other'])
        ->and($stored($directRow))->toBeNull()
        ->and($stored($shopifyRow))->toBeNull();
});

it('puts the requirement back on the method when rolled back', function (): void {
    $migration = require database_path('migrations/2026_11_10_234907_move_otdr_protection_orders_to_shipping_method_postage_sources_table.php');

    $methodId = DB::table('shipping_methods')->insertGetId(['name' => 'Prime', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('shipping_method_postage_sources')->insert([
        'shipping_method_id' => $methodId,
        'source_kind' => 'amazon',
        'otdr_protection_orders' => '["prime"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->down();

    expect(Schema::hasColumn('shipping_method_postage_sources', 'otdr_protection_orders'))->toBeFalse()
        ->and(json_decode((string) DB::table('shipping_methods')->where('id', $methodId)->value('otdr_protection_orders'), true))->toBe(['prime']);
});
