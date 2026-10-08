<?php

use App\Enums\DutiesSupport;
use App\Services\Customs\DutiesSupportTable;
use Carbon\CarbonImmutable;

/**
 * `international-customs-terms/04`: carrier duties support is sourced data
 * (ADR-0008 decision 4), and a malformed entry fails here rather than at a
 * rate.
 *
 * @return array<string, mixed>
 */
function committedDutiesSupport(): array
{
    return json_decode((string) file_get_contents(resource_path('data/customs/duties-support.json')), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * A throwaway copy of the table, never the committed file: the suite runs
 * under Paratest, and every other worker is reading that one.
 *
 * @param  array<string, mixed>  $table
 */
function temporaryDutiesSupport(array $table): string
{
    $path = sys_get_temp_dir().'/duties-support-'.bin2hex(random_bytes(8)).'.json';
    file_put_contents($path, json_encode($table, JSON_PRETTY_PRINT));
    register_shutdown_function(static fn (): bool => @unlink($path));

    return $path;
}

it('is valid as committed', function (): void {
    expect(DutiesSupportTable::errors(committedDutiesSupport()))->toBe([])
        ->and((new DutiesSupportTable)->version())->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

it('sources every USPS entry from the International Mail Manual', function (): void {
    $usps = committedDutiesSupport()['carriers']['usps'];

    expect($usps['authority'])->toBe('IMM')
        ->and($usps['default']['support'])->toBe('ddu_only')
        ->and($usps['default']['source'])->toStartWith('https://pe.usps.com/text/imm/');

    foreach ($usps['countries'] as $country => $entry) {
        expect($entry['source'])->toStartWith('https://pe.usps.com/text/imm/', "{$country} has no IMM source")
            ->and($entry['checked'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    }
});

it('records the IMM table: six countries require DDP, twelve cannot take it, LU and the rest are DDU only', function (): void {
    $table = new DutiesSupportTable;
    $today = CarbonImmutable::parse('2026-10-08');

    foreach (['DE', 'BE', 'DK', 'FI', 'FR', 'PT'] as $country) {
        expect($table->supportFor('USPS', $country, $today)?->support)->toBe(DutiesSupport::DdpRequired);
    }

    foreach (['BG', 'HR', 'CY', 'CZ', 'GR', 'HU', 'IE', 'LV', 'LT', 'PL', 'RO', 'SI'] as $country) {
        expect($table->supportFor('USPS', $country, $today)?->support)->toBe(DutiesSupport::DduOnly);
    }

    foreach (['AT', 'EE', 'IT', 'MT', 'NL', 'SK', 'ES', 'SE', 'CA', 'GB'] as $country) {
        expect($table->supportFor('USPS', $country, $today)?->support)->toBe(DutiesSupport::Either);
    }

    expect($table->supportFor('USPS', 'LU', $today)?->support)->toBe(DutiesSupport::DduOnly)
        ->and($table->supportFor('USPS', 'LU', $today)?->isDefault)->toBeTrue()
        ->and($table->supportFor('UPS', 'DE', $today)?->support)->toBe(DutiesSupport::Either)
        ->and($table->supportFor('FedEx', 'PL', $today)?->support)->toBe(DutiesSupport::Either)
        ->and($table->supportFor('DHL Express', 'DE', $today))->toBeNull();
});

it('names what is wrong with a malformed table', function (string $problem, Closure $break, string $message): void {
    $table = committedDutiesSupport();
    $break($table);

    expect(DutiesSupportTable::errors($table))->toContain($message);
})->with([
    'an unknown carrier' => ['carrier', function (array &$table): void {
        $table['carriers']['dhl'] = $table['carriers']['ups'];
    }, 'carriers.dhl is not a carrier PolyBag sends duties terms to.'],
    'a non-ISO country' => ['country', function (array &$table): void {
        $table['carriers']['usps']['countries']['EU'] = $table['carriers']['usps']['countries']['DE'];
    }, 'carriers.usps.countries.EU is not an ISO 3166-1 alpha-2 country code.'],
    'a bad support value' => ['support', function (array &$table): void {
        $table['carriers']['usps']['countries']['DE']['support'] = 'ddp';
    }, 'carriers.usps.countries.DE.support must be one of ddp_required, either, ddu_only.'],
    'an entry with no source' => ['source', function (array &$table): void {
        unset($table['carriers']['usps']['countries']['FR']['source']);
    }, 'carriers.usps.countries.FR.source must name where the entry comes from.'],
    'a default with no source' => ['default source', function (array &$table): void {
        $table['carriers']['ups']['default']['source'] = '';
    }, 'carriers.ups.default.source must name where the entry comes from.'],
    'a source that is neither a URL nor a file' => ['source path', function (array &$table): void {
        $table['carriers']['fedex']['default']['source'] = 'docs/nowhere.md';
    }, 'carriers.fedex.default.source must be an https URL or a path in this repository.'],
    'an entry never checked' => ['checked', function (array &$table): void {
        $table['carriers']['usps']['countries']['NL']['checked'] = 'last week';
    }, 'carriers.usps.countries.NL.checked must be the ISO date the source was read.'],
    'a malformed effective date' => ['effective_from', function (array &$table): void {
        $table['carriers']['usps']['countries']['NL']['effective_from'] = '2026-02-30';
    }, 'carriers.usps.countries.NL.effective_from must be an ISO date.'],
]);

it('ignores an entry until its effective date, and applies it from then on', function (): void {
    $table = committedDutiesSupport();
    $table['carriers']['usps']['countries']['NL'] = [
        'support' => 'ddp_required',
        'source' => 'https://pe.usps.com/text/imm/immc3_017.htm',
        'checked' => '2026-10-08',
        'effective_from' => '2026-11-01',
    ];

    expect(DutiesSupportTable::errors($table))->toBe([]);

    $support = new DutiesSupportTable(temporaryDutiesSupport($table));

    $before = $support->supportFor('USPS', 'NL', CarbonImmutable::parse('2026-10-31 23:00'));
    $after = $support->supportFor('USPS', 'NL', CarbonImmutable::parse('2026-11-01 00:00'));

    expect($before?->support)->toBe(DutiesSupport::DduOnly)
        ->and($before?->isDefault)->toBeTrue()
        ->and($after?->support)->toBe(DutiesSupport::DdpRequired)
        ->and($after?->effectiveFrom?->toDateString())->toBe('2026-11-01');
});
