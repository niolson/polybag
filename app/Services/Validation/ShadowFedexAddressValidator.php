<?php

namespace App\Services\Validation;

use App\Models\Shipment;
use Illuminate\Support\Str;

/**
 * FedEx asked after another validator settled the address
 * (`address-validation-routing/10`). It reads FedEx exactly as the live
 * validator does, but writes only to the copy of the Shipment it is given and
 * never saves, so the Shipment's result can't change.
 */
class ShadowFedexAddressValidator extends FedexAddressValidator
{
    protected function persist(Shipment $shipment): void {}

    /**
     * Which parts of the address FedEx returned differently from the settled
     * result, as flags rather than strings so no copy of the address is kept.
     * Each side is the validated field where the validator wrote one and the
     * entered field otherwise, as rating and labels read it. Only the first
     * street line is compared: FedEx drops the unit line outside the US.
     *
     * @return array{street_differs: bool, house_number_differs: bool, city_differs: bool, postcode_differs: bool}
     */
    public function compare(Shipment $settled, Shipment $shadow): array
    {
        $country = $shadow->country ?? 'US';
        $settledStreet = (string) ($settled->validated_address1 ?? $settled->address1);
        $shadowStreet = (string) ($shadow->validated_address1 ?? $shadow->address1);

        return [
            'street_differs' => $this->text($settledStreet) !== $this->text($shadowStreet),
            'house_number_differs' => $this->houseNumbers($settledStreet) !== $this->houseNumbers($shadowStreet)
                && ! $this->sameHouseNumber($settledStreet, $shadowStreet, $country),
            'city_differs' => $this->text($settled->validated_city ?? $settled->city) !== $this->text($shadow->validated_city ?? $shadow->city),
            'postcode_differs' => $this->postcode($settled->validated_postal_code ?? $settled->postal_code, $country)
                !== $this->postcode($shadow->validated_postal_code ?? $shadow->postal_code, $country),
        ];
    }

    /**
     * Case, accents, punctuation and spacing aside. Abbreviations are not
     * expanded, so `Street` and `ST` read as different.
     */
    private function text(?string $value): string
    {
        return trim((string) preg_replace('~[^A-Z0-9]+~', ' ', strtoupper(Str::ascii((string) $value))));
    }

    /**
     * Spaces and hyphens aside, and a US or Puerto Rico ZIP+4 compared as its
     * five-digit ZIP, since validators differ in whether they return the add-on.
     */
    private function postcode(?string $value, string $country): string
    {
        $postcode = (string) preg_replace('~[\s-]+~', '', mb_strtoupper((string) $value));

        return AddressValidationCountries::fedexReadsDeliveryPoint($country)
            ? substr($postcode, 0, 5)
            : $postcode;
    }
}
