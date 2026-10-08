<?php

namespace App\Support;

/**
 * The one test of what counts as an exporter EIN: the nine-digit Employer
 * Identification Number of the US principal party in interest, stored without
 * the hyphen and shown as `XX-XXXXXXX`. It is the client's own number, never a
 * 3PL's (15 CFR 30.6(a)(1)(iii)) — `international-customs-terms/11`.
 */
final class ExporterEin
{
    public const string FORMAT = 'nine digits, such as 12-3456789';

    /**
     * The EIN as stored: hyphen and spaces removed. Blank input is null.
     */
    public static function normalize(?string $ein): ?string
    {
        $stored = preg_replace('/[\s-]+/', '', (string) $ein) ?? '';

        return $stored === '' ? null : $stored;
    }

    public static function isValid(?string $ein): bool
    {
        return preg_match('/^\d{9}$/', (string) self::normalize($ein)) === 1;
    }

    /**
     * Why a value is not an EIN, or null when it is (or is blank).
     */
    public static function error(?string $ein): ?string
    {
        return self::normalize($ein) === null || self::isValid($ein)
            ? null
            : 'An EIN must be '.self::FORMAT.'.';
    }

    /**
     * The EIN as shown: `XX-XXXXXXX`. A value that is not nine digits is
     * returned as given.
     */
    public static function format(?string $ein): ?string
    {
        $stored = self::normalize($ein);

        return $stored !== null && self::isValid($stored)
            ? substr($stored, 0, 2).'-'.substr($stored, 2)
            : $stored;
    }
}
