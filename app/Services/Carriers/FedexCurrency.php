<?php

namespace App\Services\Carriers;

/**
 * Translates an ISO 4217 currency into the code FedEx declares it under
 * (`order-currency/02`).
 *
 * FedEx's currency codes are its own, and for many currencies not ISO's: the
 * pound is `UKL`, the peso `NMP`, the Swiss franc `SFR`, the yen `JYE`. The
 * table is the FedEx API currency codes list on FedEx's developer portal, the
 * list the REST API's `currency` fields link to. Where it gives two codes, as
 * `UKL/GBP`, the ISO one "can only be used as a preferred currency in rate
 * requests", so a declaration sends FedEx's. FedEx's own Mexico test cases
 * send `NMP`. A currency with no row here is not guessed at: the declaration
 * converts to US dollars instead.
 *
 * Left out: the Venezuelan bolívar fuerte (`VEF`), which is not ISO's current
 * `VES`.
 */
final class FedexCurrency
{
    /**
     * ISO 4217 code => FedEx code.
     *
     * @var array<string, string>
     */
    private const CODES = [
        'AED' => 'DHS',
        'ANG' => 'ANG',
        'ARS' => 'ARN',
        'AUD' => 'AUD',
        'AWG' => 'AWG',
        'BBD' => 'BBD',
        'BGN' => 'BGN',
        'BHD' => 'BHD',
        'BMD' => 'BMD',
        'BND' => 'BND',
        'BRL' => 'BRL',
        'BSD' => 'BSD',
        'CAD' => 'CAD',
        'CHF' => 'SFR',
        'CLP' => 'CHP',
        'CNY' => 'CNY',
        'COP' => 'COP',
        'CRC' => 'CRC',
        'CZK' => 'CZK',
        'DKK' => 'DKK',
        'DOP' => 'RDD',
        'EGP' => 'EGP',
        'EUR' => 'EUR',
        'GBP' => 'UKL',
        'GTQ' => 'GTQ',
        'HKD' => 'HKD',
        'HUF' => 'HUF',
        'IDR' => 'IDR',
        'ILS' => 'ILS',
        'INR' => 'INR',
        'JMD' => 'JAD',
        'JPY' => 'JYE',
        'KES' => 'KES',
        'KRW' => 'WON',
        'KWD' => 'KUD',
        'KYD' => 'CID',
        'KZT' => 'KZT',
        'LYD' => 'LYD',
        'MOP' => 'MOP',
        'MUR' => 'MUR',
        'MXN' => 'NMP',
        'MYR' => 'MYR',
        'MZN' => 'MZN',
        'NOK' => 'NOK',
        'NZD' => 'NZD',
        'PAB' => 'PAB',
        'PHP' => 'PHP',
        'PKR' => 'PKR',
        'PLN' => 'PLN',
        'RON' => 'RON',
        'RUB' => 'RUB',
        'SAR' => 'SAR',
        'SBD' => 'SBD',
        'SEK' => 'SEK',
        'SGD' => 'SID',
        'THB' => 'THB',
        'TOP' => 'TOP',
        'TRY' => 'TRY',
        'TTD' => 'TTD',
        'TWD' => 'NTD',
        'UGX' => 'UGX',
        'USD' => 'USD',
        'UYU' => 'UYU',
        'VND' => 'VND',
        'WST' => 'WST',
        'XCD' => 'ECD',
        'ZAR' => 'ZAR',
    ];

    /**
     * The ISO 4217 codes FedEx has a code for.
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        return array_keys(self::CODES);
    }

    /**
     * FedEx's code for an ISO 4217 currency, or null when it has none.
     */
    public static function codeFor(string $iso): ?string
    {
        return self::CODES[strtoupper($iso)] ?? null;
    }
}
