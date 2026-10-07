<?php

namespace App\Support;

/**
 * The one test of what counts as an export ITN: the Internal Transaction
 * Number AESDirect returns once EEI is filed, `X` followed by 14 digits. The
 * Shipment form and the Database import both ask it (ADR-0008, PRD
 * *Data model*).
 */
final class ExportItn
{
    public const string FORMAT = 'X followed by 14 digits';

    /**
     * The ITN as stored and declared: upper case, with spaces removed.
     */
    public static function normalize(string $itn): string
    {
        return strtoupper(preg_replace('/\s+/', '', $itn) ?? $itn);
    }

    public static function isValid(?string $itn): bool
    {
        return $itn !== null && preg_match('/^X\d{14}$/', self::normalize($itn)) === 1;
    }

    /**
     * Why a value is not an ITN, or null when it is.
     */
    public static function error(?string $itn): ?string
    {
        return self::isValid($itn) ? null : 'An export ITN must be '.self::FORMAT.'.';
    }
}
