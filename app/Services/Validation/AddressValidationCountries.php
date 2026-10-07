<?php

namespace App\Services\Validation;

/**
 * The per-country lists that route international address validation
 * (`address-validation-routing/08`), each entry citing the evidence for it.
 *
 * The evidence is the production comparison of 2026-09-30: per country, 10
 * real addresses and 10 with the house number inflated to n*10+7, which
 * mostly doesn't exist, sent to FedEx and to Google. "Settled" counts what
 * FedexAddressValidator's international reading accepts: matched, the
 * `StreetAddress` attribute true, and the same house number returned.
 * "Matched" counts FedEx's bare `Matched` flag, which is how it fooled us.
 * Ten per group is a sorting test, not a rate; re-run the comparison before
 * widening the trusted list.
 */
final class AddressValidationCountries
{
    /**
     * Where FedEx reads the US delivery-point attributes (`Resolved`, `DPV`)
     * rather than the international reference-data match.
     */
    public const FEDEX_DELIVERY_POINT = ['US', 'PR'];

    /**
     * Tried before Google where FedEx may validate. The lower-coverage
     * entries settle fewer real addresses, but a miss falls through to
     * Google, so the only cost is a smaller saving (decided 2026-10-01).
     *
     * @var array<string, string>
     */
    public const FEDEX_TRUSTED = [
        'AT' => 'settled 8/10 real, 0/10 inflated',
        'CH' => 'settled 10/10 real, 0/10 inflated',
        'CZ' => 'settled 10/10 real, 0/10 inflated; Google accepted 2/10 real',
        'DE' => 'settled 9/10 real, 0/10 inflated',
        'ES' => 'settled 5/10 real, 0/10 inflated',
        'FR' => 'settled 10/10 real, 0/10 inflated',
        'IT' => 'settled 10/10 real, 1/10 inflated (a short number that may exist)',
        'LV' => 'settled 7/10 real, 1/10 inflated (a short number that may exist)',
        'MX' => 'settled 9/10 real, 0/10 inflated',
        'NL' => 'settled 9/10 real, 0/10 inflated',
        'PL' => 'settled 9/10 real, 1/10 inflated (a short number that may exist)',
        'DK' => 'lower coverage: settled 7/10 real, 0/10 inflated',
        'FI' => 'lower coverage: settled 6/10 real, 0/10 inflated',
        'LT' => 'lower coverage: settled 7/10 real, 1/10 inflated (a short number that may exist)',
        'LU' => 'lower coverage: settled 7/10 real, 0/10 inflated; reference data from 2016',
        'NO' => 'lower coverage: settled 1/10 real, 0/10 inflated; matched 8/10 inflated by substituting another number',
        'SI' => 'lower coverage: settled 3/10 real, 0/10 inflated',
        'CL' => 'lower coverage: settled 6/10 real, 0/10 inflated',
    ];

    /**
     * Never sent to FedEx: it matched inflated house numbers at street level
     * and echoed the fake number back. The `StreetAddress` reading rejects
     * all of those (0/10 settled in each), so the shadow check in
     * `address-validation-routing/10` may lift these.
     *
     * @var array<string, string>
     */
    public const FEDEX_EXCLUDED = [
        'BE' => 'matched 7/10 inflated, echoing the number at street level',
        'BR' => 'matched 4/10 inflated, echoing the number at street level',
        'PT' => 'matched 5/10 inflated, echoing the number or substituting another',
    ];

    /**
     * Google Address Validation answers "Unsupported region code"; FedEx is
     * the last resort here.
     *
     * @var array<string, string>
     */
    public const GOOGLE_UNSUPPORTED = [
        'HK' => 'Google rejected 20/20; FedEx settled 0/10 real',
        'LI' => 'Google rejected 9/9; FedEx settled 3/9 real',
        'UY' => 'Google rejected 20/20; FedEx settled 1/10 real',
    ];

    /**
     * Where a house number such as `482/22` is a land-registry number and a
     * street (orientation) number, and FedEx returns the second alone. In
     * other countries the second part can be the flat, as in Polish `12/3`.
     */
    public const TWO_PART_HOUSE_NUMBERS = ['CZ', 'SK'];

    public static function twoPartHouseNumbers(string $country): bool
    {
        return in_array($country, self::TWO_PART_HOUSE_NUMBERS, true);
    }

    public static function fedexReadsDeliveryPoint(string $country): bool
    {
        return in_array($country, self::FEDEX_DELIVERY_POINT, true);
    }

    /**
     * FedEx goes ahead of every other validator.
     */
    public static function fedexFirst(string $country): bool
    {
        return self::fedexReadsDeliveryPoint($country)
            || isset(self::FEDEX_TRUSTED[$country]);
    }

    /**
     * FedEx goes after Google, which can't answer here.
     */
    public static function fedexLastResort(string $country): bool
    {
        return ! self::fedexFirst($country)
            && ! isset(self::FEDEX_EXCLUDED[$country])
            && ! self::googleSupports($country);
    }

    public static function fedexSupports(string $country): bool
    {
        return self::fedexFirst($country) || self::fedexLastResort($country);
    }

    public static function googleSupports(string $country): bool
    {
        return ! isset(self::GOOGLE_UNSUPPORTED[$country]);
    }
}
