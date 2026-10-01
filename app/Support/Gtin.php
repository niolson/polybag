<?php

namespace App\Support;

/**
 * The one test of what counts as a GTIN — a GTIN-8, UPC (GTIN-12), EAN (GTIN-13)
 * or GTIN-14 with a correct GS1 check digit. The product form and the customs
 * declaration both ask it, so a value the form accepts is a value sent as an
 * S-PID, and an internal Code 128 barcode is neither.
 */
final class Gtin
{
    private const array LENGTHS = [8, 12, 13, 14];

    public static function isValid(?string $value): bool
    {
        if ($value === null || ! ctype_digit($value) || ! in_array(strlen($value), self::LENGTHS, true)) {
            return false;
        }

        return (int) $value[-1] === self::checkDigit(substr($value, 0, -1));
    }

    /**
     * The GS1 check digit for the digits that precede it: weights alternate 3, 1
     * starting from the rightmost digit.
     */
    public static function checkDigit(string $digits): int
    {
        $sum = 0;

        foreach (array_reverse(str_split($digits)) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }
}
