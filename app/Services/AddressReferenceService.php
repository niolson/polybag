<?php

namespace App\Services;

use CommerceGuys\Addressing\AddressFormat\AddressField;
use CommerceGuys\Addressing\AddressFormat\AddressFormatRepository;
use CommerceGuys\Addressing\Country\CountryRepository;
use CommerceGuys\Addressing\Subdivision\Subdivision;
use CommerceGuys\Addressing\Subdivision\SubdivisionRepository;
use Illuminate\Support\Str;

class AddressReferenceService
{
    private CountryRepository $countryRepository;

    private SubdivisionRepository $subdivisionRepository;

    private AddressFormatRepository $addressFormatRepository;

    /** @var array<string, string>|null */
    private ?array $countryOptions = null;

    /** @var array<string, string> */
    private array $countryLookup = [];

    /** @var array<string, array<string, string>> */
    private array $subdivisionOptions = [];

    /** @var array<string, array<string, string>> */
    private array $subdivisionLookup = [];

    /** @var array<string, string> */
    private array $administrativeAreaLabels = [];

    public function __construct()
    {
        $this->countryRepository = new CountryRepository('en');
        $this->subdivisionRepository = new SubdivisionRepository();
        $this->addressFormatRepository = new AddressFormatRepository();
    }

    /**
     * @return array<string, string>
     */
    public function getCountryOptions(): array
    {
        if ($this->countryOptions !== null) {
            return $this->countryOptions;
        }

        $countries = $this->countryRepository->getAll();
        $options = [];

        foreach ($countries as $country) {
            $code = $country->getCountryCode();
            $options[$code] = $country->getName();

            $this->countryLookup[$this->normalizeLookupKey($code)] = $code;

            if ($country->getThreeLetterCode()) {
                $this->countryLookup[$this->normalizeLookupKey($country->getThreeLetterCode())] = $code;
            }

            if ($country->getNumericCode()) {
                $this->countryLookup[$this->normalizeLookupKey($country->getNumericCode())] = $code;
            }

            $this->countryLookup[$this->normalizeLookupKey($country->getName())] = $code;
        }

        asort($options);

        return $this->countryOptions = $options;
    }

    /**
     * @return array<string, string>
     */
    public function getSubdivisionOptions(?string $countryCode): array
    {
        $countryCode = $this->normalizeCountry($countryCode);

        if (! $countryCode) {
            return [];
        }

        if (array_key_exists($countryCode, $this->subdivisionOptions)) {
            return $this->subdivisionOptions[$countryCode];
        }

        $options = [];
        $lookup = [];

        foreach ($this->subdivisionRepository->getAll([$countryCode]) as $subdivision) {
            $code = strtoupper($subdivision->getCode());

            $options[$code] = $subdivision->getName();
            $lookup[$this->normalizeLookupKey($code)] = $code;
            $lookup[$this->normalizeLookupKey($subdivision->getId())] = $code;
            $lookup[$this->normalizeLookupKey("{$countryCode}-{$subdivision->getId()}")] = $code;
            $lookup[$this->normalizeLookupKey($subdivision->getName())] = $code;

            if ($subdivision->getLocalCode()) {
                $lookup[$this->normalizeLookupKey($subdivision->getLocalCode())] = $code;
            }

            if ($subdivision->getLocalName()) {
                $lookup[$this->normalizeLookupKey($subdivision->getLocalName())] = $code;
            }
        }

        asort($options);

        $this->subdivisionLookup[$countryCode] = $lookup;

        return $this->subdivisionOptions[$countryCode] = $options;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getAllSubdivisionOptions(): array
    {
        $optionsByCountry = [];

        foreach (array_keys($this->getCountryOptions()) as $countryCode) {
            $options = $this->getSubdivisionOptions($countryCode);

            if ($options !== []) {
                $optionsByCountry[$countryCode] = $options;
            }
        }

        return $optionsByCountry;
    }

    public function normalizeCountry(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = $this->normalizeLookupKey($value);

        if ($normalized === '') {
            return null;
        }

        $this->getCountryOptions();

        return $this->countryLookup[$normalized] ?? null;
    }

    public function normalizeSubdivision(?string $countryCode, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $countryCode = $this->normalizeCountry($countryCode);
        $normalized = $this->normalizeLookupKey($value);

        if ($countryCode === null || $normalized === '') {
            return trim($value) !== '' ? trim($value) : null;
        }

        $this->getSubdivisionOptions($countryCode);

        return $this->subdivisionLookup[$countryCode][$normalized] ?? (trim($value) !== '' ? trim($value) : null);
    }

    public function usesAdministrativeArea(?string $countryCode): bool
    {
        $countryCode = $this->normalizeCountry($countryCode);

        if (! $countryCode) {
            return false;
        }

        return in_array(AddressField::ADMINISTRATIVE_AREA, $this->addressFormatRepository->get($countryCode)->getUsedFields(), true);
    }

    public function isAdministrativeAreaRequired(?string $countryCode): bool
    {
        $countryCode = $this->normalizeCountry($countryCode);

        if (! $countryCode) {
            return false;
        }

        return in_array(AddressField::ADMINISTRATIVE_AREA, $this->addressFormatRepository->get($countryCode)->getRequiredFields(), true);
    }

    public function getAdministrativeAreaLabel(?string $countryCode): string
    {
        $countryCode = $this->normalizeCountry($countryCode);

        if (! $countryCode) {
            return 'State / Province';
        }

        if (isset($this->administrativeAreaLabels[$countryCode])) {
            return $this->administrativeAreaLabels[$countryCode];
        }

        $type = $this->addressFormatRepository->get($countryCode)->getAdministrativeAreaType();

        $label = match ($type) {
            'state' => 'State',
            'province' => 'Province',
            'prefecture' => 'Prefecture',
            'department' => 'Department',
            'district' => 'District',
            'region' => 'Region',
            'county' => 'County',
            'emirate' => 'Emirate',
            'parish' => 'Parish',
            'canton' => 'Canton',
            'island' => 'Island',
            'area' => 'Area',
            default => 'State / Province',
        };

        return $this->administrativeAreaLabels[$countryCode] = $label;
    }

    public function normalizeAddressFields(array $data, string $countryField = 'country', string $subdivisionField = 'state_or_province'): array
    {
        $country = $this->normalizeCountry($data[$countryField] ?? null);

        if ($country !== null) {
            $data[$countryField] = $country;
        } elseif (isset($data[$countryField]) && is_string($data[$countryField])) {
            $trimmedCountry = trim($data[$countryField]);
            $data[$countryField] = $trimmedCountry !== '' ? strtoupper($trimmedCountry) : null;
        }

        if (array_key_exists($subdivisionField, $data)) {
            $data[$subdivisionField] = $this->normalizeSubdivision($data[$countryField] ?? null, $data[$subdivisionField]);
        }

        return $data;
    }

    private function normalizeLookupKey(string $value): string
    {
        return (string) Str::of(Str::ascii($value))
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', ' ')
            ->trim();
    }
}
