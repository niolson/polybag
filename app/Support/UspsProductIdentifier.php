<?php

namespace App\Support;

/**
 * The one test of which EU product identifiers USPS is sent. USPS's
 * `europeanUnionProductID` patterns allow only letters and digits, so the
 * adapter strips everything else and cuts to the field's length; the customs
 * readiness check must agree on which identifiers survive, or a consumer line
 * would reach USPS with no block.
 *
 * Until USPS says how punctuated SKUs are meant to be sent, this is the only
 * place that changes if USPS answers differently.
 */
final class UspsProductIdentifier
{
    public const int MERCHANT_MAX_LENGTH = 50;

    public const int MANUFACTURER_MAX_LENGTH = 70;

    public const int STANDARD_MAX_LENGTH = 50;

    /**
     * The `merchantProductIdentifier` as sent, or null when nothing is left.
     */
    public static function merchant(?string $identifier): ?string
    {
        return self::alphanumeric($identifier, self::MERCHANT_MAX_LENGTH);
    }

    /**
     * The `nonstandardizedManufacturerProductIdentifier` as sent, or null when
     * nothing is left.
     */
    public static function manufacturer(?string $identifier): ?string
    {
        return self::alphanumeric($identifier, self::MANUFACTURER_MAX_LENGTH);
    }

    /**
     * The `standardizedManufacturerProductIdentifier` as sent, or null when
     * nothing is left.
     */
    public static function standard(?string $identifier): ?string
    {
        return self::alphanumeric($identifier, self::STANDARD_MAX_LENGTH);
    }

    private static function alphanumeric(?string $identifier, int $maxLength): ?string
    {
        if ($identifier === null) {
            return null;
        }

        $stripped = mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $identifier) ?? '', 0, $maxLength);

        return $stripped === '' ? null : $stripped;
    }
}
