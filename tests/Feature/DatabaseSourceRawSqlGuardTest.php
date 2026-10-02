<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

function guardedSource(array $config): DatabaseSource
{
    return new DatabaseSource(array_merge([
        'connection' => config('database.default'),
        'data_source_id' => 7,
    ], $config));
}

it('refuses a destructive mark_exported query before executing it', function (): void {
    // A canary table the destructive query would wipe if the guard let it run.
    DB::statement('CREATE TABLE canary (id INTEGER)');
    DB::table('canary')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);

    $source = guardedSource([
        'mark_exported' => [
            'enabled' => true,
            'query' => 'DELETE FROM canary WHERE :shipment_reference IS NOT NULL',
        ],
    ]);

    $threw = false;
    try {
        $source->markExported('ref-1');
    } catch (InvalidArgumentException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    // The DELETE never ran…
    expect(DB::table('canary')->count())->toBe(3);
    // …and nothing was audited as executed.
    expect(AuditLog::where('action', AuditAction::DataSourceQueryExecuted)->exists())->toBeFalse();
});

it('refuses a non-SELECT custom shipments query', function (): void {
    $source = guardedSource([
        'shipments_query' => 'DELETE FROM shipments',
    ]);

    expect(fn (): Collection => $source->fetchShipments())->toThrow(InvalidArgumentException::class);
});

it('refuses a CREATE TABLE export query (self-referential DDL)', function (): void {
    $source = guardedSource([
        'export' => [
            'enabled' => true,
            'query' => 'CREATE TABLE evil (id INTEGER)',
        ],
    ]);

    expect(fn (): ?string => $source->exportPackage([]))->toThrow(InvalidArgumentException::class);
});

it('still runs a legitimate UPDATE mark_exported query', function (): void {
    DB::statement('CREATE TABLE marks (ref TEXT, exported TEXT)');
    DB::table('marks')->insert(['ref' => 'ref-1', 'exported' => 'n']);

    $source = guardedSource([
        'mark_exported' => [
            'enabled' => true,
            'query' => "UPDATE marks SET exported = 'y' WHERE ref = :shipment_reference",
        ],
    ]);

    expect($source->markExported('ref-1'))->toBeTrue();
    expect(DB::table('marks')->where('ref', 'ref-1')->value('exported'))->toBe('y');
});

// ── Affected-row cap (issue 07 follow-up) ─────────────────────────────────────

it('rolls back and throws when a mark_exported UPDATE affects more than the cap', function (): void {
    DB::statement('CREATE TABLE marks (ref TEXT, exported TEXT)');
    DB::table('marks')->insert([
        ['ref' => 'a', 'exported' => 'n'],
        ['ref' => 'b', 'exported' => 'n'],
        ['ref' => 'c', 'exported' => 'n'],
    ]);

    // Missing/too-broad WHERE: this UPDATE would touch every row.
    $source = guardedSource([
        'mark_exported' => [
            'enabled' => true,
            'query' => "UPDATE marks SET exported = 'y' WHERE :shipment_reference IS NOT NULL",
        ],
    ]);

    expect(fn (): bool => $source->markExported('ref-1'))->toThrow(RuntimeException::class);

    // Rolled back — no row was actually flipped.
    expect(DB::table('marks')->where('exported', 'y')->count())->toBe(0);
});

it('honors a raised per-source max_affected_rows cap', function (): void {
    DB::statement('CREATE TABLE marks (ref TEXT, exported TEXT)');
    DB::table('marks')->insert([
        ['ref' => 'a', 'exported' => 'n'],
        ['ref' => 'b', 'exported' => 'n'],
        ['ref' => 'c', 'exported' => 'n'],
    ]);

    $source = guardedSource([
        'max_affected_rows' => 5,
        'mark_exported' => [
            'enabled' => true,
            'query' => "UPDATE marks SET exported = 'y' WHERE :shipment_reference IS NOT NULL",
        ],
    ]);

    // 3 affected <= cap of 5, so it commits.
    expect($source->markExported('ref-1'))->toBeTrue();
    expect(DB::table('marks')->where('exported', 'y')->count())->toBe(3);
});

it('records the affected-row count in the audit log on success', function (): void {
    DB::statement('CREATE TABLE marks (ref TEXT, exported TEXT)');
    DB::table('marks')->insert(['ref' => 'ref-1', 'exported' => 'n']);

    $source = guardedSource([
        'mark_exported' => [
            'enabled' => true,
            'query' => "UPDATE marks SET exported = 'y' WHERE ref = :shipment_reference",
        ],
    ]);

    $source->markExported('ref-1');

    $log = AuditLog::where('action', AuditAction::DataSourceQueryExecuted)->firstOrFail();
    expect($log->metadata['status'])->toBe('success')
        ->and($log->metadata['affected_rows'])->toBe(1);
});

it('caps the export write as well', function (): void {
    DB::statement('CREATE TABLE dest (note TEXT)');
    DB::table('dest')->insert([['note' => 'x'], ['note' => 'y']]);

    $source = guardedSource([
        'export' => [
            'enabled' => true,
            'query' => 'UPDATE dest SET note = :tracking_number',
        ],
    ]);

    expect(fn (): ?string => $source->exportPackage(['tracking_number' => 'TRK1']))->toThrow(RuntimeException::class);
    expect(DB::table('dest')->where('note', 'TRK1')->count())->toBe(0);
});
