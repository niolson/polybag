<?php

namespace App\Services\Carriers;

use Illuminate\Support\Str;

/**
 * Translates a stored state/province into the two-letter code FedEx accepts.
 *
 * FedEx takes `stateOrProvinceCode` only for the countries below, only as its
 * own two-letter codes, and rejects anything longer with
 * `STATEORPROVINCECODE.MAXCHAREXCEEDED` — failing the whole rate, ship or
 * address-validation request. `AddressReferenceService` stores the addressing
 * library's codes, which for Mexico are abbreviations (`PUE.`, `CDMX`) and for
 * India and the UAE are full names, so they need translating. Every other
 * country gets no state at all, which FedEx accepts and fills in itself.
 */
final class FedexSubdivision
{
    /**
     * Countries whose stored subdivision is already the FedEx code.
     */
    private const PASS_THROUGH = ['US', 'CA', 'PR'];

    /**
     * FedEx code => the stored values (library codes, current and former
     * names) that mean it. Matched after {@see key()} strips accents,
     * punctuation and spaces, and a FedEx code always matches itself.
     *
     * Missing on purpose: India's Telangana and Ladakh, and the merged
     * "Dadra and Nagar Haveli and Daman and Diu", which have no FedEx code.
     * They are sent without a state rather than guessed.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const CODES = [
        'MX' => [
            'AG' => ['Ags.', 'Aguascalientes'],
            'BC' => ['B.C.', 'Baja California'],
            'BS' => ['B.C.S.', 'Baja California Sur'],
            'CM' => ['Camp.', 'Campeche'],
            'CS' => ['Chis.', 'Chiapas'],
            'CH' => ['Chih.', 'Chihuahua'],
            'DF' => ['CDMX', 'Ciudad de México', 'Distrito Federal'],
            'CO' => ['Coah.', 'Coahuila', 'Coahuila de Zaragoza'],
            'CL' => ['Col.', 'Colima'],
            'DG' => ['Dgo.', 'Durango'],
            'EM' => ['Méx.', 'Estado de México', 'México'],
            'GT' => ['Gto.', 'Guanajuato'],
            'GR' => ['Gro.', 'Guerrero'],
            'HG' => ['Hgo.', 'Hidalgo'],
            'JA' => ['Jal.', 'Jalisco'],
            'MI' => ['Mich.', 'Michoacán', 'Michoacán de Ocampo'],
            'MO' => ['Mor.', 'Morelos'],
            'NA' => ['Nay.', 'Nayarit'],
            'NL' => ['N.L.', 'Nuevo León'],
            'OA' => ['Oax.', 'Oaxaca'],
            'PU' => ['Pue.', 'Puebla'],
            'QE' => ['Qro.', 'Querétaro'],
            'QR' => ['Q.R.', 'Q. Roo', 'Quintana Roo'],
            'SL' => ['S.L.P.', 'San Luis Potosí'],
            'SI' => ['Sin.', 'Sinaloa'],
            'SO' => ['Son.', 'Sonora'],
            'TB' => ['Tab.', 'Tabasco'],
            'TM' => ['Tamps.', 'Tamaulipas'],
            'TL' => ['Tlax.', 'Tlaxcala'],
            'VE' => ['Ver.', 'Veracruz', 'Veracruz de Ignacio de la Llave'],
            'YU' => ['Yuc.', 'Yucatán'],
            'ZA' => ['Zac.', 'Zacatecas'],
        ],
        'IN' => [
            'AN' => ['Andaman and Nicobar Islands', 'Andaman & Nicobar'],
            'AP' => ['Andhra Pradesh'],
            'AR' => ['Arunachal Pradesh'],
            'AS' => ['Assam'],
            'BR' => ['Bihar'],
            'CG' => ['Chhattisgarh', 'Chattisgarh'],
            'CH' => ['Chandigarh'],
            'DL' => ['Delhi', 'NCT of Delhi', 'New Delhi'],
            'GA' => ['Goa'],
            'GJ' => ['Gujarat'],
            'HR' => ['Haryana'],
            'HP' => ['Himachal Pradesh'],
            'JK' => ['Jammu and Kashmir', 'Jammu & Kashmir'],
            'JH' => ['Jharkhand'],
            'KA' => ['Karnataka'],
            'KL' => ['Kerala'],
            'LD' => ['Lakshadweep'],
            'MP' => ['Madhya Pradesh'],
            'MH' => ['Maharashtra'],
            'MN' => ['Manipur'],
            'ML' => ['Meghalaya'],
            'MZ' => ['Mizoram'],
            'NL' => ['Nagaland'],
            'OR' => ['Odisha', 'Orissa'],
            'PB' => ['Punjab'],
            'PY' => ['Puducherry', 'Pondicherry'],
            'RJ' => ['Rajasthan'],
            'SK' => ['Sikkim'],
            'TN' => ['Tamil Nadu'],
            'TR' => ['Tripura'],
            'UA' => ['Uttarakhand', 'Uttaranchal'],
            'UP' => ['Uttar Pradesh'],
            'WB' => ['West Bengal'],
        ],
        'AE' => [
            'AB' => ['Abu Dhabi'],
            'AJ' => ['Ajman'],
            'DU' => ['Dubai'],
            'FU' => ['Fujairah'],
            'RA' => ['Ras al-Khaimah'],
            'SH' => ['Sharjah'],
            'UM' => ['Umm al-Quwain'],
        ],
    ];

    /** @var array<string, array<string, string>>|null */
    private static ?array $lookup = null;

    /**
     * The FedEx code for this subdivision, or null when FedEx should be sent
     * no state for it.
     */
    public static function code(?string $country, ?string $subdivision): ?string
    {
        $country = strtoupper(trim((string) $country));
        $key = self::key((string) $subdivision);

        if ($key === '') {
            return null;
        }

        if (in_array($country, self::PASS_THROUGH, true)) {
            return strlen($key) === 2 ? $key : null;
        }

        return self::lookup()[$country][$key] ?? null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function lookup(): array
    {
        if (self::$lookup !== null) {
            return self::$lookup;
        }

        $lookup = [];

        foreach (self::CODES as $country => $codes) {
            foreach ($codes as $code => $aliases) {
                $lookup[$country][$code] = $code;

                foreach ($aliases as $alias) {
                    $lookup[$country][self::key($alias)] = $code;
                }
            }
        }

        return self::$lookup = $lookup;
    }

    private static function key(string $value): string
    {
        return (string) Str::of(Str::ascii($value))->upper()->replaceMatches('/[^A-Z0-9]+/', '');
    }
}
