<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The migration that replaces `box_sizes.fedex_package_type` with
 * `carrier_packaging`, exercised by hand in both directions from the migrated
 * schema the suite starts in: `down()` first to get the old column back, then
 * `up()` over rows written the old way. It is an anonymous class, so the
 * `require` result is left untyped as in `PackageLabelRecordTest`.
 */
const CARRIER_PACKAGING_MIGRATION = 'database/migrations/2026_09_16_000000_replace_fedex_package_type_with_carrier_packaging_on_box_sizes.php';

/**
 * @param  array<string, mixed>  $attributes
 */
function insertBoxSizeRow(string $code, array $attributes): int
{
    return DB::table('box_sizes')->insertGetId([
        'code' => $code,
        'label' => $code,
        'type' => 'BOX',
        'height' => 4,
        'width' => 4,
        'length' => 4,
        'max_weight' => 35,
        'empty_weight' => 0,
        ...$attributes,
    ]);
}

const FEDEX_PACKAGE_TYPE_TWINS = [
    'FEDEX_ENVELOPE' => 'fedex_envelope',
    'FEDEX_PAK' => 'fedex_pak',
    'FEDEX_TUBE' => 'fedex_tube',
    'FEDEX_BOX' => 'fedex_box',
    'FEDEX_EXTRA_SMALL_BOX' => 'fedex_extra_small_box',
    'FEDEX_SMALL_BOX' => 'fedex_small_box',
    'FEDEX_MEDIUM_BOX' => 'fedex_medium_box',
    'FEDEX_LARGE_BOX' => 'fedex_large_box',
    'FEDEX_EXTRA_LARGE_BOX' => 'fedex_extra_large_box',
    'FEDEX_10KG_BOX' => 'fedex_10kg_box',
    'FEDEX_25KG_BOX' => 'fedex_25kg_box',
];

it('carries every FedEx package type across losslessly and nulls only the packer\'s own', function (): void {
    $migration = require base_path(CARRIER_PACKAGING_MIGRATION);
    $migration->down();

    expect(Schema::hasColumn('box_sizes', 'fedex_package_type'))->toBeTrue()
        ->and(Schema::hasColumn('box_sizes', 'carrier_packaging'))->toBeFalse();

    foreach (FEDEX_PACKAGE_TYPE_TWINS as $fedexPackageType => $unused) {
        insertBoxSizeRow($fedexPackageType, ['fedex_package_type' => $fedexPackageType]);
    }
    insertBoxSizeRow('OWN', ['fedex_package_type' => 'YOUR_PACKAGING']);
    insertBoxSizeRow('UNSET', ['fedex_package_type' => null]);

    $migration->up();

    expect(Schema::hasColumn('box_sizes', 'fedex_package_type'))->toBeFalse()
        ->and(DB::table('box_sizes')->pluck('carrier_packaging', 'code')->all())
        ->toBe([...FEDEX_PACKAGE_TYPE_TWINS, 'OWN' => null, 'UNSET' => null])
        ->and(DB::table('box_sizes')->whereNull('carrier_packaging')->count())->toBe(2);

    $migration->down();

    expect(Schema::hasColumn('box_sizes', 'carrier_packaging'))->toBeFalse()
        ->and(DB::table('box_sizes')->pluck('fedex_package_type', 'code')->all())
        ->toBe([
            ...array_combine(array_keys(FEDEX_PACKAGE_TYPE_TWINS), array_keys(FEDEX_PACKAGE_TYPE_TWINS)),
            'OWN' => 'YOUR_PACKAGING',
            'UNSET' => 'YOUR_PACKAGING',
        ]);

    DB::table('box_sizes')->delete();
    $migration->up();
});

it('refuses to roll back while a box size carries a packaging FedEx\'s enum cannot hold, naming the rows', function (): void {
    $migration = require base_path(CARRIER_PACKAGING_MIGRATION);

    insertBoxSizeRow('PAK', ['carrier_packaging' => 'fedex_pak']);
    $mediumId = insertBoxSizeRow('MFRB', ['label' => 'USPS Medium Flat Rate Box', 'carrier_packaging' => 'usps_medium_flat_rate_box']);
    $upsId = insertBoxSizeRow('UPSL', ['label' => 'UPS Letter', 'carrier_packaging' => 'ups_letter']);

    expect(fn () => $migration->down())->toThrow(
        RuntimeException::class,
        "#{$mediumId} MFRB (USPS Medium Flat Rate Box): usps_medium_flat_rate_box; #{$upsId} UPSL (UPS Letter): ups_letter",
    );

    // Refused before touching the schema or the rows.
    expect(Schema::hasColumn('box_sizes', 'carrier_packaging'))->toBeTrue()
        ->and(Schema::hasColumn('box_sizes', 'fedex_package_type'))->toBeFalse()
        ->and(DB::table('box_sizes')->where('code', 'MFRB')->value('carrier_packaging'))->toBe('usps_medium_flat_rate_box');
});
