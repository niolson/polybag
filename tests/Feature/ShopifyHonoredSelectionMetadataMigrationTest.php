<?php

use App\Models\Package;

/**
 * Packages labeled before the American-spelling rename carry the selection
 * under `shopify_honoured_selection`; the inferrer now reads only the new key.
 */
function runHonoredSelectionMigration(string $direction = 'up'): void
{
    $migration = require database_path('migrations/2026_10_02_175817_rename_shopify_honored_selection_metadata_key.php');

    $migration->{$direction}();
}

it('moves the old metadata key onto the new one and leaves the rest of the metadata alone', function (): void {
    $package = Package::factory()->create([
        'metadata' => ['shopify_honoured_selection' => 'usps:Priority', 'other' => 'kept'],
    ]);
    $untouched = Package::factory()->create(['metadata' => ['other' => 'kept']]);

    runHonoredSelectionMigration();

    expect($package->refresh()->metadata)->toBe(['other' => 'kept', 'shopify_honored_selection' => 'usps:Priority'])
        ->and($untouched->refresh()->metadata)->toBe(['other' => 'kept']);
});

it('keeps a null selection as a null under the new key', function (): void {
    $package = Package::factory()->create(['metadata' => ['shopify_honoured_selection' => null]]);

    runHonoredSelectionMigration();

    expect($package->refresh()->metadata)->toBe(['shopify_honored_selection' => null]);
});

it('restores the old key on rollback', function (): void {
    $package = Package::factory()->create(['metadata' => ['shopify_honored_selection' => 'usps:Priority']]);

    runHonoredSelectionMigration('down');

    expect($package->refresh()->metadata)->toBe(['shopify_honoured_selection' => 'usps:Priority']);
});
