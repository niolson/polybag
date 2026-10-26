<?php

use Illuminate\Support\Facades\Schema;

/**
 * `capacity-audit/07`: the columns a list sorts by on first load need an index, or the
 * database sorts every row to show the first page.
 */
it('indexes the columns the Packages list and the Prepaid Duties report sort by', function (string $table, string $column): void {
    expect(Schema::hasIndex($table, [$column]))->toBeTrue();
})->with([
    'Packages list' => ['packages', 'created_at'],
    'Prepaid Duties report' => ['package_labels', 'ship_date'],
]);

it('indexes the Shipment columns search matches by prefix', function (string $column): void {
    expect(Schema::hasIndex('shipments', [$column]))->toBeTrue();
})->with(['first_name', 'last_name', 'address1', 'city', 'email']);
