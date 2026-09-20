<?php

namespace App\DataTransferObjects\Shipping;

use App\Enums\DestinationZone;
use App\Models\Location;
use App\Models\Shipment;
use App\Services\PhoneParserService;

readonly class AddressData
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $streetAddress,
        public string $city,
        public ?string $stateOrProvince,
        public ?string $postalCode,
        public string $country = 'US',
        public ?string $streetAddress2 = null,
        public ?string $company = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $phoneExtension = null,
        public ?string $uspsCarrierRoute = null,
        public ?bool $residential = null,
    ) {}

    public static function fromShipment(Shipment $shipment): self
    {
        return new self(
            firstName: $shipment->first_name ?? '',
            lastName: $shipment->last_name ?? '',
            streetAddress: $shipment->validated_address1 ?? $shipment->address1,
            streetAddress2: $shipment->validated_address2 ?? $shipment->address2,
            city: $shipment->validated_city ?? $shipment->city,
            stateOrProvince: $shipment->validated_state_or_province ?? $shipment->state_or_province,
            postalCode: $shipment->validated_postal_code ?? $shipment->postal_code,
            country: $shipment->validated_country ?? $shipment->country ?? 'US',
            company: $shipment->validated_company ?? $shipment->company,
            phone: PhoneParserService::carrierDigits($shipment->phone_e164, $shipment->phone, $shipment->validated_country ?? $shipment->country ?? 'US'),
            email: $shipment->email,
            phoneExtension: $shipment->phone_extension,
            uspsCarrierRoute: $shipment->validated_carrier_route,
            residential: $shipment->validated_residential ?? $shipment->residential,
        );
    }

    public static function fromLocation(Location $location): self
    {
        return new self(
            firstName: $location->first_name,
            lastName: $location->last_name,
            streetAddress: $location->address1,
            streetAddress2: $location->address2,
            city: $location->city,
            stateOrProvince: $location->state_or_province,
            postalCode: $location->postal_code,
            country: $location->country,
            company: $location->company,
            phone: PhoneParserService::carrierDigits($location->phone_e164, $location->phone, $location->country),
        );
    }

    public static function fromConfig(): self
    {
        $location = Location::getDefault();

        if (! $location) {
            throw new \RuntimeException('No default location configured. Go to Settings > Locations and set a default location.');
        }

        return self::fromLocation($location);
    }

    /**
     * The destination classification carriers should price and purchase.
     *
     * Null remains meaningful on the DTO: neither validation nor the import
     * supplied a classification. Carrier requests conservatively treat that
     * unknown as residential instead of producing an optimistic commercial
     * quote.
     */
    public function isResidential(): bool
    {
        return $this->residential ?? true;
    }

    /**
     * Overseas military and diplomatic post office subdivisions.
     *
     * @var array<int, string>
     */
    private const MILITARY_SUBDIVISIONS = ['AA', 'AE', 'AP'];

    /**
     * City names identifying the same destinations, used when a subdivision
     * arrives unnormalized.
     *
     * @var array<int, string>
     */
    private const MILITARY_CITIES = ['APO', 'FPO', 'DPO'];

    /**
     * Whether this is a United States territory or possession.
     */
    public function isUsTerritory(): bool
    {
        if ($this->country !== 'US') {
            return false;
        }

        return in_array(
            strtoupper(trim((string) $this->stateOrProvince)),
            DestinationZone::UsTerritories->states(),
            true,
        );
    }

    /**
     * Whether this is an overseas military or diplomatic post office address.
     */
    public function isMilitary(): bool
    {
        if ($this->country !== 'US') {
            return false;
        }

        return in_array(strtoupper(trim((string) $this->stateOrProvince)), self::MILITARY_SUBDIVISIONS, true)
            || in_array(strtoupper(trim($this->city)), self::MILITARY_CITIES, true);
    }

    /**
     * PO Box / rural-route-box / highway-contract-box / general-delivery
     * detector. Requires the box keyword to be immediately followed by a box
     * number (digits, optionally letter-suffixed, or a leading letter), which
     * is what distinguishes a real box number from a compound word like
     * "Boxwood" or "Box Canyon" -- a plain \b can't do this alone since digits
     * are word characters too ("Box186" has no \b between "x" and "1").
     *
     * Validated against 3M+ real addresses (see polybag-demo-data-tools
     * bin/test-po-box-regex.php) with no confirmed false positives. "BOX"
     * immediately preceded by "LOCK" is deliberately excluded via negative
     * lookbehind (not just by omitting a "LOCK BOX" keyword -- plain "BOX"
     * would still match "LOCK BOX 1144" on its own, since \b only checks the
     * boundary right before "BOX", not what word came before that) -- every
     * "LOCK BOX"/"LOCKBOX" match in that sample was a real deliverable street
     * address paired with a property-access lockbox delivery note, not a USPS
     * Lock Box rental.
     */
    private const PO_BOX_PATTERN = '/\b(?:'
        .'(?:P\.?\s*O\.?\s*(?:BOX|DRAWER)|POST\s*OFFICE\s*(?:BOX|DRAWER)|POB|(?<!LOCK)(?<!LOCK )BOX|CMR'
        .'|(?:RURAL\s*ROUTE|RR|R\.?R\.?)\s*\d*\s*,?\s*BOX'
        .'|(?:HIGHWAY\s*CONTRACT(?:\s*ROUTE)?|HC|H\.?C\.?)\s*\d*\s*,?\s*BOX'
        .'|STAR\s*ROUTE\s*,?\s*BOX'
        .')[\s\.\-#]*(?:(?:NO|NUMBER)\.?[\s\.\-#]*)?(?:\d+[A-Z]?|[A-Z]\d*)\b'
        .'|GENERAL\s*DELIVERY\b'
        .')/i';

    /**
     * Whether this is a PO Box (or box-only rural/highway-contract/general
     * delivery point) that only USPS -- or a carrier whose last mile runs
     * through USPS, e.g. FedEx Ground Economy, UPS Ground Saver -- can reach.
     *
     * Prefers the USPS-licensed carrier route from address validation
     * (carrier route "B..." is a dedicated PO Box route) when available,
     * since it's authoritative. Falls back to the regex otherwise -- address
     * validation costs money now, so a meaningful share of addresses won't
     * have a carrier route to check.
     */
    public function isPoBox(): bool
    {
        if ($this->country !== 'US') {
            return false;
        }

        if ($this->uspsCarrierRoute !== null) {
            return str_starts_with(strtoupper($this->uspsCarrierRoute), 'B');
        }

        return preg_match(self::PO_BOX_PATTERN, $this->streetAddress) === 1
            || ($this->streetAddress2 !== null && preg_match(self::PO_BOX_PATTERN, $this->streetAddress2) === 1);
    }

    /**
     * The customs area this address sits in.
     *
     * The fifty states share one area; every territory and the military post
     * offices are their own, which is why a country code alone cannot answer
     * whether a shipment clears customs.
     */
    private function customsZone(): string
    {
        if ($this->country !== 'US') {
            return $this->country;
        }

        if ($this->isMilitary()) {
            return 'US-MILITARY';
        }

        if ($this->isUsTerritory()) {
            return 'US-'.strtoupper(trim((string) $this->stateOrProvince));
        }

        return 'US';
    }

    /**
     * Whether a shipment between this address and the given one stays inside a
     * single customs area, and so carries no declaration.
     *
     * Carriers ask about the pair, not either address alone: a label from
     * Canada into Pennsylvania needs customs even though the destination is an
     * ordinary US address.
     */
    public function sharesCustomsZoneWith(self $other): bool
    {
        return $this->customsZone() === $other->customsZone();
    }

    /**
     * Whether a shipment to this address carries a customs declaration when it
     * originates in the fifty states.
     *
     * Military and diplomatic post offices, along with the territories, cross a
     * customs boundary despite being domestic addresses, so "customs applies" is
     * not the same question as "the country is not US". Anything reasoning about
     * customs data should ask this rather than comparing the country itself, or
     * the two answers drift apart — which is how customs items reached the
     * carrier without passing through weight reconciliation first, and how
     * Puerto Rico labels reached FedEx with no customs value on them.
     */
    public function requiresCustomsDeclaration(): bool
    {
        return $this->customsZone() !== 'US';
    }
}
