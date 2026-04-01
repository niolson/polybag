<?php

namespace App\Services;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

class PhoneParserService
{
    public static function parse(string $rawPhone, ?string $defaultRegion = 'US'): PhoneParseResult
    {
        $util = PhoneNumberUtil::getInstance();
        $defaultRegion = strtoupper($defaultRegion ?: 'US');

        try {
            $phoneNumber = $util->parse($rawPhone, $defaultRegion);

            if (! $util->isValidNumber($phoneNumber)) {
                return new PhoneParseResult(
                    phone: null,
                    e164: null,
                    extension: null,
                    error: "Invalid phone number: {$rawPhone}",
                );
            }

            $nationalNumber = (string) $phoneNumber->getNationalNumber();
            $e164 = $util->format($phoneNumber, \libphonenumber\PhoneNumberFormat::E164);
            $extension = $phoneNumber->getExtension();

            // Truncate extension to 6 chars (FedEx max)
            if ($extension !== null && $extension !== '') {
                $extension = substr($extension, 0, 6);
            } else {
                $extension = null;
            }

            return new PhoneParseResult(
                phone: $nationalNumber,
                e164: $e164,
                extension: $extension,
            );
        } catch (NumberParseException $e) {
            return new PhoneParseResult(
                phone: null,
                e164: null,
                extension: null,
                error: "Unable to parse phone number: {$rawPhone}",
            );
        }
    }

    public static function nationalDigits(?string $phoneE164, ?string $defaultRegion = 'US'): ?string
    {
        if (! $phoneE164) {
            return null;
        }

        return self::parse($phoneE164, $defaultRegion)->phone;
    }
}
