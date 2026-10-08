<?php

use App\Enums\DutiesSupport;
use App\Enums\TaxRegistrationRegime;
use App\Services\Customs\DutiesSupportTable;
use App\Services\Shipping\DutiesTermsFilter;
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
        ->and((new DutiesSupportTable)->version())->toMatch('/^\d{4}-\d{2}-\d{2}(\.\d+)?$/');
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

it('records the IMM table: eight countries require DDP, twelve cannot take it, the rest are DDU only', function (): void {
    $table = new DutiesSupportTable;
    $today = CarbonImmutable::parse('2026-10-08');

    // LU is missing from IMM 360's own table but its country page requires DDP;
    // the IMM lists Monaco under France.
    foreach (['DE', 'BE', 'DK', 'FI', 'FR', 'PT', 'LU', 'MC'] as $country) {
        expect($table->supportFor('USPS', $country, $today)?->support)->toBe(DutiesSupport::DdpRequired);
    }

    foreach (['BG', 'HR', 'CY', 'CZ', 'GR', 'HU', 'IE', 'LV', 'LT', 'PL', 'RO', 'SI'] as $country) {
        expect($table->supportFor('USPS', $country, $today)?->support)->toBe(DutiesSupport::DduOnly);
    }

    foreach (['AT', 'EE', 'IT', 'MT', 'NL', 'SK', 'ES', 'SE', 'CA', 'GB'] as $country) {
        expect($table->supportFor('USPS', $country, $today)?->support)->toBe(DutiesSupport::Either);
    }

    expect($table->supportFor('USPS', 'JP', $today)?->support)->toBe(DutiesSupport::DduOnly)
        ->and($table->supportFor('USPS', 'JP', $today)?->isDefault)->toBeTrue()
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
    'an override for an unknown regime' => ['regime', function (array &$table): void {
        $table['carriers']['usps']['countries']['AT']['with_registration']['vat'] = $table['carriers']['usps']['countries']['AT']['with_registration']['ioss'];
    }, 'carriers.usps.countries.AT.with_registration.vat is not a known registration regime (ioss, uk_vat, voec, arn).'],
    'an override with a bad support value' => ['override support', function (array &$table): void {
        $table['carriers']['usps']['countries']['AT']['with_registration']['ioss']['support'] = 'ddp';
    }, 'carriers.usps.countries.AT.with_registration.ioss.support must be one of ddp_required, either, ddu_only.'],
    'an override with no source' => ['override source', function (array &$table): void {
        unset($table['carriers']['usps']['countries']['SE']['with_registration']['ioss']['source']);
    }, 'carriers.usps.countries.SE.with_registration.ioss.source must name where the entry comes from.'],
    'an override with no checked date' => ['override checked', function (array &$table): void {
        unset($table['carriers']['usps']['countries']['SE']['with_registration']['ioss']['checked']);
    }, 'carriers.usps.countries.SE.with_registration.ioss.checked must be the ISO date the source was read.'],
    'an override on a default' => ['default override', function (array &$table): void {
        $table['carriers']['usps']['default']['with_registration'] = $table['carriers']['usps']['countries']['AT']['with_registration'];
    }, 'carriers.usps.default.with_registration is never read; an override belongs on a country entry.'],
    'an unknown key in a country entry' => ['country key', function (array &$table): void {
        $table['carriers']['usps']['countries']['AT']['with_registrations'] = [];
    }, 'carriers.usps.countries.AT.with_registrations is not a known key (support, source, checked, effective_from, note, with_registration).'],
    'an unknown key in a default' => ['default key', function (array &$table): void {
        $table['carriers']['usps']['default']['suport'] = 'either';
    }, 'carriers.usps.default.suport is not a known key (support, source, checked, effective_from, note).'],
    'an unknown key in an override' => ['override key', function (array &$table): void {
        $table['carriers']['usps']['countries']['AT']['with_registration']['ioss']['effective_form'] = '2026-11-01';
    }, 'carriers.usps.countries.AT.with_registration.ioss.effective_form is not a known key (support, source, checked, authority).'],
    'an override for a regime that cannot cover the country' => ['uncovered regime', function (array &$table): void {
        $table['carriers']['usps']['countries']['AT']['with_registration']['uk_vat'] = $table['carriers']['usps']['countries']['AT']['with_registration']['ioss'];
    }, 'carriers.usps.countries.AT.with_registration.uk_vat can never apply: a UK VAT (GB) registration does not cover AT.'],
    'an empty override list' => ['override list', function (array &$table): void {
        $table['carriers']['usps']['countries']['AT']['with_registration'] = [];
    }, 'carriers.usps.countries.AT.with_registration must be an object keyed by registration regime.'],
]);

it('answers AT and SE with the IOSS override only for a declared IOSS registration', function (): void {
    $table = new DutiesSupportTable;
    $today = CarbonImmutable::parse('2026-10-08');

    foreach (['AT', 'SE'] as $country) {
        $without = $table->supportFor('USPS', $country, $today);
        $with = $table->supportFor('USPS', $country, $today, TaxRegistrationRegime::Ioss);

        expect($without?->support)->toBe(DutiesSupport::Either)
            ->and($without?->registration)->toBeNull()
            ->and($table->supportFor('USPS', $country, $today, TaxRegistrationRegime::UkVat)?->support)->toBe(DutiesSupport::Either)
            ->and($with?->support)->toBe(DutiesSupport::DdpRequired)
            ->and($with?->registration)->toBe(TaxRegistrationRegime::Ioss)
            ->and($with?->authority)->toBe('Swiss Post')
            ->and($with?->source)->toBe('https://www.post.ch/en/pages/eu-customs-reform-2026')
            ->and($with?->checked->toDateString())->toBe('2026-10-08')
            ->and($table->supportFor('UPS', $country, $today, TaxRegistrationRegime::Ioss)?->support)->toBe(DutiesSupport::Either);
    }
});

it('gates an override with the entry\'s effective date', function (): void {
    $table = committedDutiesSupport();
    $table['carriers']['usps']['countries']['AT']['effective_from'] = '2026-11-01';

    $support = new DutiesSupportTable(temporaryDutiesSupport($table));

    expect($support->supportFor('USPS', 'AT', CarbonImmutable::parse('2026-10-31'), TaxRegistrationRegime::Ioss)?->support)->toBe(DutiesSupport::DduOnly)
        ->and($support->supportFor('USPS', 'AT', CarbonImmutable::parse('2026-11-01'), TaxRegistrationRegime::Ioss)?->support)->toBe(DutiesSupport::DdpRequired);
});

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

it('names every registration regime in a reason', function (): void {
    $name = new ReflectionMethod(DutiesTermsFilter::class, 'registrationName');

    expect(collect(TaxRegistrationRegime::cases())->mapWithKeys(
        fn (TaxRegistrationRegime $regime): array => [$regime->value => $name->invoke(app(DutiesTermsFilter::class), $regime)],
    )->all())->toBe([
        'ioss' => 'an IOSS number',
        'uk_vat' => 'a UK VAT number',
        'voec' => 'a VOEC number',
        'arn' => 'an ARN',
    ]);
});
