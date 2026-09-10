<?php

use App\Services\ServiceInference\ServiceRuleset;
use Illuminate\Support\Facades\Cache;

/**
 * Build a throwaway ruleset directory.
 *
 * Never mutates the committed tables: the suite runs under Paratest, so a test
 * that rewrites a file every other worker is reading is both a source of
 * nondeterminism and a way to leave the working tree dirty if the run dies.
 *
 * @param  array<string, mixed>  $overrides  Table name to replacement contents
 */
function temporaryRuleset(array $overrides = []): string
{
    $source = resource_path('data/service-inference');
    $directory = sys_get_temp_dir().'/service-inference-'.bin2hex(random_bytes(8));

    mkdir($directory);

    // Cleanup is registered here, at creation, rather than in an afterEach.
    // Registering it with the directory in hand means it cannot depend on a
    // registry surviving the test lifecycle -- which under Paratest it
    // intermittently did not -- it runs even if the test aborts before teardown,
    // and it is scoped to this one directory, so no worker can ever remove
    // another's.
    register_shutdown_function(static function () use ($directory): void {
        array_map(unlink(...), glob("{$directory}/*.json") ?: []);
        @rmdir($directory);
    });

    // Read the table list off the committed directory rather than restating it.
    // `ServiceRuleset` loads every table together or not at all, so a hardcoded
    // list here silently stops matching the moment a table is added -- and the
    // failure lands in whichever test happens to use a temporary ruleset, not in
    // the change that caused it.
    foreach (glob("{$source}/*.json") ?: [] as $path) {
        $table = basename($path, '.json');

        $contents = array_key_exists($table, $overrides)
            ? json_encode($overrides[$table], JSON_PRETTY_PRINT)
            : (string) file_get_contents($path);

        file_put_contents("{$directory}/{$table}.json", $contents);
    }

    return $directory;
}

it('reports the version the tables were loaded with', function (): void {
    expect((new ServiceRuleset)->version())->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

it('resolves a service type code, and falls through on one that names no product', function (): void {
    $ruleset = new ServiceRuleset;

    expect($ruleset->uspsProductForServiceTypeCode('001'))->toBe('USPS Ground Advantage')
        ->and($ruleset->uspsProductForServiceTypeCode('909'))->toBeNull()
        ->and($ruleset->uspsProductForServiceTypeCode('999'))->toBeNull();
});

// The version stamp is the whole point: a package recorded under one has to be
// re-derivable against the tables that produced it. Caching the version and the
// tables independently lets one outlive the other across a deploy, stamping a
// value derived from old rules with a new version -- and the re-run path only
// replaces stamps that are older, so that package is never re-derived.
it('resolves a UPS service level indicator, and falls through on one it has no evidence for', function (): void {
    $ruleset = new ServiceRuleset;

    expect($ruleset->upsServiceForServiceIndicator('YW'))->toBe('UPS Ground Saver')
        ->and($ruleset->upsServiceForServiceIndicator('03'))->toBe('UPS Ground')
        // International, where the indicator and UPS's API service code diverge:
        // Worldwide Express is 66 here and 07 in the API, so the API code must
        // resolve to nothing rather than to the service it names elsewhere.
        ->and($ruleset->upsServiceForServiceIndicator('66'))->toBe('UPS Worldwide Express')
        ->and($ruleset->upsServiceForServiceIndicator('07'))->toBeNull()
        ->and($ruleset->upsServiceForServiceIndicator('YN'))->toBeNull();
});

it('does not serve the version and the tables from independent caches', function (): void {
    (new ServiceRuleset)->version();

    expect(Cache::has('service-inference-ruleset:ruleset'))->toBeFalse()
        ->and(Cache::has('service-inference-ruleset:usps-impb-stc'))->toBeFalse();
});

it('reads the version and the lookup tables from the same ruleset', function (): void {
    $directory = temporaryRuleset([
        'ruleset' => ['version' => '2099-01-01'],
        'usps-impb-stc' => [
            'effective_date' => '2099-01-01',
            'codes' => ['001' => ['product' => 'Renamed Product']],
        ],
    ]);

    $ruleset = new ServiceRuleset($directory);

    expect($ruleset->version())->toBe('2099-01-01')
        ->and($ruleset->uspsProductForServiceTypeCode('001'))->toBe('Renamed Product');
});

it('does not carry one ruleset over into another', function (): void {
    $directory = temporaryRuleset(['ruleset' => ['version' => '2099-01-01']]);

    expect((new ServiceRuleset($directory))->version())->toBe('2099-01-01')
        ->and((new ServiceRuleset)->version())->not->toBe('2099-01-01');
});
