<?php

namespace App\Services\ServiceInference;

/**
 * A USPS Intelligent Mail package barcode number, parsed and validated.
 *
 * USPS issues it in a 22-digit and a 26-digit form. They differ in how wide the
 * Mailer ID and serial are and share what this class reads: a 2-digit
 * application identifier, a 3-digit Service Type Code, and a mod-10 check digit
 * over everything before it. Human transcriptions and some carrier APIs prefix
 * the whole thing with the GS1 `420` application identifier and the destination
 * ZIP, which is stripped here.
 *
 * Validation is not decoration. A mistyped number with plausible digits in the
 * service position is the one way the tracking-number rung produces a confident
 * wrong answer instead of no answer, so nothing reads `serviceTypeCode` without
 * the check digit having passed first. Ambiguity is held to the same standard:
 * where a string reads as two different valid barcodes, it parses as neither.
 */
readonly class ImpbTrackingNumber
{
    private function __construct(
        public string $digits,
        public string $serviceTypeCode,
    ) {}

    /** The barcode lengths USPS issues. Anything else is not an IMpb. */
    private const LENGTHS = [22, 26];

    /**
     * Parse a tracking number, or return null if it is not a valid IMpb.
     */
    public static function tryParse(?string $trackingNumber): ?self
    {
        $digits = preg_replace('/\D/', '', $trackingNumber ?? '') ?? '';

        $readings = self::validReadings($digits);

        // Exactly one, or none. Two readings mean two different service
        // positions, and picking either is the confident wrong answer this
        // class exists to avoid.
        if (count($readings) !== 1) {
            return null;
        }

        return new self($readings[0], substr($readings[0], 2, 3));
    }

    /**
     * Every way this digit string reads as a valid barcode.
     *
     * A `420`-prefixed string is ambiguous once both lengths are in play: 34
     * digits is a 5-digit ZIP over a 26-digit barcode and equally a 9-digit
     * ZIP+4 over a 22-digit one, and the two readings start the barcode four
     * digits apart.
     *
     * @return list<string>
     */
    private static function validReadings(string $digits): array
    {
        $offsets = str_starts_with($digits, '420') ? [0, 8, 12] : [0];

        $readings = [];

        foreach ($offsets as $offset) {
            $candidate = substr($digits, $offset);

            if (in_array(strlen($candidate), self::LENGTHS, true) && self::checkDigitIsValid($candidate)) {
                $readings[] = $candidate;
            }
        }

        return $readings;
    }

    /**
     * USPS mod-10: weight 3 and 1 alternating, from the rightmost body digit.
     */
    private static function checkDigitIsValid(string $digits): bool
    {
        $body = substr($digits, 0, -1);
        $stated = (int) substr($digits, -1);

        $sum = 0;
        $weight = 3;

        foreach (array_reverse(str_split($body)) as $digit) {
            $sum += (int) $digit * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return $stated === (10 - ($sum % 10)) % 10;
    }
}
