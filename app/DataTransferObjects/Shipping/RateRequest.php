<?php

namespace App\DataTransferObjects\Shipping;

use App\Models\Package;
use App\Services\SpecialServiceResolver;
use Carbon\CarbonImmutable;

readonly class RateRequest
{
    /**
     * @param  array<PackageData>  $packages
     * @param  array<int, string>  $specialServiceCodes
     * @param  array<string, array<string, mixed>>  $specialServiceConfig  Per-code config values (e.g. declared_value amount)
     */
    public function __construct(
        public string $originPostalCode,
        public string $destinationPostalCode,
        public string $originCountry = 'US',
        public string $destinationCountry = 'US',
        public ?string $destinationCity = null,
        public ?string $destinationStateOrProvince = null,
        public ?bool $residential = null,
        public array $packages = [],
        public array $specialServiceCodes = [],
        public ?int $locationId = null,
        public ?int $clientId = null,
        public ?CarbonImmutable $shipDate = null,
        public array $specialServiceConfig = [],
        public ?string $originCity = null,
        public ?string $originStateOrProvince = null,
        public ?float $contentsValue = null,
        public ?int $packageId = null,
    ) {}

    public static function fromPackage(Package $package): self
    {
        $shipment = $package->shipment;
        $origin = AddressData::fromConfig();

        if ($package->location) {
            $origin = AddressData::fromLocation($package->location);
        } elseif ($package->location_id) {
            $package->load('location');
            if ($package->location) {
                $origin = AddressData::fromLocation($package->location);
            }
        }

        $resolver = app(SpecialServiceResolver::class);
        $specialServiceCodes = $resolver->resolveForPackage($package);

        return new self(
            originPostalCode: $origin->postalCode ?? '',
            destinationPostalCode: $shipment->validated_postal_code ?? $shipment->postal_code,
            originCountry: $origin->country,
            destinationCountry: $shipment->validated_country ?? $shipment->country ?? 'US',
            destinationCity: $shipment->validated_city ?? $shipment->city,
            destinationStateOrProvince: $shipment->validated_state_or_province ?? $shipment->state_or_province,
            residential: $shipment->validated_residential ?? $shipment->residential,
            packages: [PackageData::fromPackage($package)],
            specialServiceCodes: $specialServiceCodes,
            locationId: $package->location_id,
            clientId: $package->shipment->client_id,
            specialServiceConfig: $resolver->configForPackage($package, $specialServiceCodes),
            originCity: $origin->city,
            originStateOrProvince: $origin->stateOrProvince,
            contentsValue: $shipment->value !== null ? (float) $shipment->value : null,
            packageId: $package->id,
        );
    }

    /**
     * A digest of everything a carrier was asked to price.
     *
     * What an offer is bound to. A price is a function of these inputs and
     * nothing else, so an offer whose package still produces the same digest
     * is still the price for that package, and one whose package does not is
     * not — whatever else was or was not saved in between. Timestamps could
     * not say that: neither `PackageItem` nor `ShipmentItem` touches its
     * parent, so a quantity or declared-value edit moved nothing, while any
     * parent save retired every offer for no reason.
     *
     * Two inputs are left out. The ship date is set per carrier after
     * `fromPackage()` and is the offer's window, not its identity; the
     * package id is already the row's `package_id`. Everything else is
     * encoded canonically — keys sorted at every depth, lists of codes
     * sorted, enums by value — so the same request always digests the same,
     * however it was built.
     */
    public function fingerprint(): string
    {
        $inputs = get_object_vars($this);
        unset($inputs['shipDate'], $inputs['packageId']);

        return hash('sha256', json_encode(self::canonical($inputs), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * The same value in a form that encodes identically however it was built.
     *
     * Associative arrays and objects are sorted by key at every depth; a list
     * of scalars — special service codes — is sorted too, since it names a
     * set. A list of objects, the packages, keeps its order: it is a sequence.
     */
    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $items = array_map(self::canonical(...), $value);

            if ($items === [] || array_all($items, fn (mixed $item): bool => is_scalar($item) || $item === null)) {
                sort($items);
            }

            return $items;
        }

        ksort($value);

        return array_map(self::canonical(...), $value);
    }

    public function hasSpecialService(string $code): bool
    {
        return in_array($code, $this->specialServiceCodes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function specialServiceConfig(string $code): array
    {
        return $this->specialServiceConfig[$code] ?? [];
    }

    public function withoutSpecialService(string $code): self
    {
        return $this->withSpecialServiceCodes(
            array_values(array_diff($this->specialServiceCodes, [$code])),
        );
    }

    /**
     * @param  array<int, string>  $codes
     */
    public function withSpecialServiceCodes(array $codes): self
    {
        return new self(
            originPostalCode: $this->originPostalCode,
            destinationPostalCode: $this->destinationPostalCode,
            originCountry: $this->originCountry,
            destinationCountry: $this->destinationCountry,
            destinationCity: $this->destinationCity,
            destinationStateOrProvince: $this->destinationStateOrProvince,
            residential: $this->residential,
            packages: $this->packages,
            specialServiceCodes: $codes,
            locationId: $this->locationId,
            clientId: $this->clientId,
            shipDate: $this->shipDate,
            specialServiceConfig: $this->specialServiceConfig,
            originCity: $this->originCity,
            originStateOrProvince: $this->originStateOrProvince,
            contentsValue: $this->contentsValue,
            packageId: $this->packageId,
        );
    }

    public function withShipDate(CarbonImmutable $date): self
    {
        return new self(
            originPostalCode: $this->originPostalCode,
            destinationPostalCode: $this->destinationPostalCode,
            originCountry: $this->originCountry,
            destinationCountry: $this->destinationCountry,
            destinationCity: $this->destinationCity,
            destinationStateOrProvince: $this->destinationStateOrProvince,
            residential: $this->residential,
            packages: $this->packages,
            specialServiceCodes: $this->specialServiceCodes,
            locationId: $this->locationId,
            clientId: $this->clientId,
            shipDate: $date,
            specialServiceConfig: $this->specialServiceConfig,
            originCity: $this->originCity,
            originStateOrProvince: $this->originStateOrProvince,
            contentsValue: $this->contentsValue,
            packageId: $this->packageId,
        );
    }
}
