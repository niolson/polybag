<?php

namespace App\Services\ServiceInference;

/**
 * A UPS 1Z tracking number, parsed and validated.
 *
 * Eighteen characters: the `1Z` prefix, a six-character shipper number, a
 * two-character service level indicator, a seven-digit package number and a
 * check digit over everything between the prefix and itself.
 *
 * Validation carries the same weight it does in `ImpbTrackingNumber`, and for a
 * sharper reason here: the service indicator sits in the middle of the number, so
 * a transposed digit anywhere before it still leaves two plausible characters in
 * the service position. The check digit is what separates a number that names a
 * service from a number that merely has something in that slot.
 */
readonly class Ups1zTrackingNumber
{
    private function __construct(
        public string $number,
        public string $serviceIndicator,
    ) {}

    private const LENGTH = 18;

    /**
     * Parse a tracking number, or return null if it is not a valid 1Z.
     */
    public static function tryParse(?string $trackingNumber): ?self
    {
        $candidate = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $trackingNumber ?? ''));

        if (strlen($candidate) !== self::LENGTH || ! str_starts_with($candidate, '1Z')) {
            return null;
        }

        // The package number is digits; the shipper number and the service
        // indicator are alphanumeric. Checking the shape before the check digit
        // keeps a string of the right length but the wrong form from being
        // scored at all.
        if (preg_match('/^1Z[A-Z0-9]{8}\d{8}$/', $candidate) !== 1) {
            return null;
        }

        if (! self::checkDigitIsValid($candidate)) {
            return null;
        }

        return new self($candidate, substr($candidate, 8, 2));
    }

    /**
     * UPS's own check digit: alphabetic characters score `(ASCII - 63) mod 10`,
     * digits score themselves, even positions count double, and the digit is what
     * rounds the total up to a multiple of ten.
     */
    private static function checkDigitIsValid(string $number): bool
    {
        $body = substr($number, 2, 15);
        $stated = (int) substr($number, -1);

        $odd = 0;
        $even = 0;

        foreach (str_split($body) as $position => $character) {
            $value = ctype_digit($character)
                ? (int) $character
                : (ord($character) - 63) % 10;

            if ($position % 2 === 0) {
                $odd += $value;
            } else {
                $even += $value;
            }
        }

        $total = $odd + $even * 2;

        return $stated === (10 - $total % 10) % 10;
    }
}
