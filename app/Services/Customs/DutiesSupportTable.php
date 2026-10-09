<?php

namespace App\Services\Customs;

use App\DataTransferObjects\Customs\DutiesSupportEntry;
use App\DataTransferObjects\Shipping\AddressData;
use App\Enums\DutiesSupport;
use App\Enums\TaxRegistrationRegime;
use App\Models\Carrier;
use App\Models\CarrierAlias;
use App\Services\AddressReferenceService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What each carrier can do with duties terms per destination, read from
 * `resources/data/customs/duties-support.json` (ADR-0008 decision 4).
 *
 * Committed sourced data rather than a table an operator edits, as
 * `resources/data/service-inference/` is: each entry keeps its source and the
 * day it was checked, and every install reads the same copy. When a country
 * changes its rules the fix is a line in the file and a release.
 */
class DutiesSupportTable
{
    /**
     * The carriers the file may name, by lookup key. A carrier PolyBag cannot
     * send terms to has no business being listed; one that is not listed is
     * not filtered at all.
     *
     * @var list<string>
     */
    public const CARRIERS = [Carrier::USPS, Carrier::UPS, Carrier::FEDEX];

    /**
     * The keys a default or country entry may carry; a country entry may also carry `with_registration`.
     *
     * @var list<string>
     */
    private const ENTRY_KEYS = ['support', 'source', 'checked', 'effective_from', 'note'];

    /** @var array<string, mixed>|null */
    private ?array $table = null;

    /**
     * @param  string|null  $path  Where the file lives. Defaults to the committed one; overridden only by tests, which must never write to that one.
     */
    public function __construct(private readonly ?string $path = null) {}

    /**
     * The version a `customs_terms` snapshot records.
     */
    public function version(): string
    {
        return (string) $this->table()['version'];
    }

    /**
     * The carrier's support for the destination on the given day, or null
     * when the file does not list the carrier.
     *
     * A country entry whose `effective_from` is later than `$on` is not in
     * force yet, and the carrier's default answers instead.
     *
     * An entry may carry a `with_registration` override for a regime: some
     * postal operators take a parcel either way but only DDP once it carries
     * an IOSS number. It applies only when `$registration` is that regime,
     * which is the registration actually declared, not one a threshold
     * withholds, and only while the entry itself is in force.
     *
     * An override is a postal rule about consumer parcels unless it carries a
     * `business_support`, which answers instead for a business recipient. A
     * limit of the carrier's API rather than of the destination post binds a
     * business parcel just as much, since the registration is still sent.
     */
    public function supportFor(string $carrier, string $country, CarbonInterface $on, ?TaxRegistrationRegime $registration = null, bool $recipientIsBusiness = false): ?DutiesSupportEntry
    {
        $key = CarrierAlias::lookupKey($carrier);
        $carrierEntry = $this->table()['carriers'][$key] ?? null;

        if (! is_array($carrierEntry)) {
            return null;
        }

        $country = strtoupper(trim($country));
        $entry = $carrierEntry['countries'][$country] ?? null;
        $isDefault = false;

        if (is_array($entry) && isset($entry['effective_from'])
            && CarbonImmutable::parse($entry['effective_from'])->startOfDay()->greaterThan($on)) {
            $entry = null;
        }

        if (! is_array($entry)) {
            $entry = $carrierEntry['default'];
            $isDefault = true;
        }

        $override = ! $isDefault && $registration !== null
            ? ($entry['with_registration'][$registration->value] ?? null)
            : null;

        if (is_array($override) && $recipientIsBusiness) {
            $override = isset($override['business_support']) ? [...$override, 'support' => $override['business_support']] : null;
        }

        if (is_array($override)) {
            return new DutiesSupportEntry(
                carrier: $key,
                country: $country,
                support: DutiesSupport::from($override['support']),
                source: (string) $override['source'],
                checked: CarbonImmutable::parse($override['checked']),
                authority: (string) ($override['authority'] ?? $carrierEntry['authority']),
                effectiveFrom: isset($entry['effective_from']) ? CarbonImmutable::parse($entry['effective_from']) : null,
                registration: $registration,
            );
        }

        return new DutiesSupportEntry(
            carrier: $key,
            country: $country,
            support: DutiesSupport::from($entry['support']),
            source: (string) $entry['source'],
            checked: CarbonImmutable::parse($entry['checked']),
            authority: (string) $carrierEntry['authority'],
            isDefault: $isDefault,
            effectiveFrom: isset($entry['effective_from']) ? CarbonImmutable::parse($entry['effective_from']) : null,
        );
    }

    /**
     * What is wrong with a duties-support table, one message per problem;
     * empty when nothing is.
     *
     * Run by the test suite against the committed file, so a carrier PolyBag
     * does not know, a country that is not ISO 3166-1 alpha-2, a support value
     * outside the three, or an entry with no source or checked date fails the
     * build rather than reaching a rate.
     *
     * @param  array<string, mixed>  $table
     * @return list<string>
     */
    public static function errors(array $table): array
    {
        $errors = [];

        if (! is_string($table['version'] ?? null)
            || preg_match('/^(\d{4}-\d{2}-\d{2})(\.\d+)?$/', $table['version'], $version) !== 1
            || ! self::isDate($version[1])) {
            $errors[] = 'version must be an ISO date, with a .N suffix for a later change the same day.';
        }

        if (! is_array($table['notes'] ?? null)) {
            $errors[] = 'notes must be a list of lines.';
        }

        $carriers = $table['carriers'] ?? null;

        if (! is_array($carriers) || $carriers === []) {
            return [...$errors, 'carriers must name at least one carrier.'];
        }

        $knownCarriers = array_map(CarrierAlias::lookupKey(...), self::CARRIERS);
        $countries = array_keys(app(AddressReferenceService::class)->getCountryOptions());

        foreach ($carriers as $key => $carrier) {
            if (! in_array($key, $knownCarriers, true)) {
                $errors[] = "carriers.{$key} is not a carrier PolyBag sends duties terms to.";

                continue;
            }

            if (! is_array($carrier)) {
                $errors[] = "carriers.{$key} must be an object.";

                continue;
            }

            if (! is_string($carrier['authority'] ?? null) || trim($carrier['authority']) === '') {
                $errors[] = "carriers.{$key}.authority must name where the carrier's rules come from.";
            }

            if (! is_array($carrier['default'] ?? null)) {
                $errors[] = "carriers.{$key}.default must give the support for unlisted countries.";
            } else {
                array_push($errors, ...self::entryErrors("carriers.{$key}.default", $carrier['default']));
                array_push($errors, ...self::unknownKeyErrors("carriers.{$key}.default", $carrier['default'], self::ENTRY_KEYS));

                if (array_key_exists('with_registration', $carrier['default'])) {
                    $errors[] = "carriers.{$key}.default.with_registration is never read; an override belongs on a country entry.";
                }
            }

            if (! is_array($carrier['countries'] ?? null)) {
                $errors[] = "carriers.{$key}.countries must be an object.";

                continue;
            }

            foreach ($carrier['countries'] as $country => $entry) {
                $path = "carriers.{$key}.countries.{$country}";

                if (! in_array($country, $countries, true)) {
                    $errors[] = "{$path} is not an ISO 3166-1 alpha-2 country code.";
                }

                if (! is_array($entry)) {
                    $errors[] = "{$path} must be an object.";

                    continue;
                }

                array_push($errors, ...self::entryErrors($path, $entry));
                array_push($errors, ...self::unknownKeyErrors($path, $entry, [...self::ENTRY_KEYS, 'with_registration']));

                if (array_key_exists('with_registration', $entry)) {
                    array_push($errors, ...self::registrationErrors("{$path}.with_registration", $entry['with_registration'], $country));
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function registrationErrors(string $path, mixed $overrides, string $country): array
    {
        if (! is_array($overrides) || $overrides === []) {
            return ["{$path} must be an object keyed by registration regime."];
        }

        $errors = [];

        foreach ($overrides as $regime => $override) {
            $overridePath = "{$path}.{$regime}";

            if (TaxRegistrationRegime::tryFrom((string) $regime) === null) {
                $errors[] = "{$overridePath} is not a known registration regime (".implode(', ', array_column(TaxRegistrationRegime::cases(), 'value')).').';
            }

            $regimeCase = TaxRegistrationRegime::tryFrom((string) $regime);

            if ($regimeCase !== null && ! $regimeCase->covers(new AddressData('', '', '', '', null, null, $country))) {
                $errors[] = "{$overridePath} can never apply: a {$regimeCase->getLabel()} registration does not cover {$country}.";
            }

            if (! is_array($override)) {
                $errors[] = "{$overridePath} must be an object.";

                continue;
            }

            array_push($errors, ...self::unknownKeyErrors($overridePath, $override, ['support', 'business_support', 'source', 'checked', 'authority', 'note']));

            if (array_key_exists('authority', $override)
                && (! is_string($override['authority']) || trim($override['authority']) === '')) {
                $errors[] = "{$overridePath}.authority must name who the rule comes from.";
            }

            if (array_key_exists('business_support', $override) && DutiesSupport::tryFrom((string) $override['business_support']) === null) {
                $errors[] = "{$overridePath}.business_support must be one of ".implode(', ', array_column(DutiesSupport::cases(), 'value')).'.';
            }

            if (array_key_exists('effective_from', $override)) {
                $errors[] = "{$overridePath}.effective_from is not supported; the entry's own effective_from gates it.";
            }

            array_push($errors, ...self::entryErrors($overridePath, $override));
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function unknownKeyErrors(string $path, array $entry, array $allowed): array
    {
        $errors = [];

        foreach (array_diff(array_keys($entry), $allowed) as $unknown) {
            $errors[] = "{$path}.{$unknown} is not a known key (".implode(', ', $allowed).').';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private static function entryErrors(string $path, array $entry): array
    {
        $errors = [];

        if (DutiesSupport::tryFrom((string) ($entry['support'] ?? '')) === null) {
            $errors[] = "{$path}.support must be one of ".implode(', ', array_column(DutiesSupport::cases(), 'value')).'.';
        }

        $source = $entry['source'] ?? null;

        if (! is_string($source) || trim($source) === '') {
            $errors[] = "{$path}.source must name where the entry comes from.";
        } elseif (! str_starts_with($source, 'https://') && ! is_file(base_path($source))) {
            $errors[] = "{$path}.source must be an https URL or a path in this repository.";
        }

        if (! is_string($entry['checked'] ?? null) || ! self::isDate($entry['checked'])) {
            $errors[] = "{$path}.checked must be the ISO date the source was read.";
        }

        if (array_key_exists('effective_from', $entry)
            && (! is_string($entry['effective_from']) || ! self::isDate($entry['effective_from']))) {
            $errors[] = "{$path}.effective_from must be an ISO date.";
        }

        return $errors;
    }

    private static function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map(intval(...), explode('-', $value));

        return checkdate($month, $day, $year);
    }

    /**
     * @return array<string, mixed>
     */
    public function table(): array
    {
        return $this->table ??= json_decode(
            (string) file_get_contents($this->path ?? resource_path('data/customs/duties-support.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
