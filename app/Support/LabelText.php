<?php

namespace App\Support;

use Illuminate\Support\Str;
use IntlChar;

/**
 * Address text made ASCII for a carrier label (`label-address-characters`).
 *
 * `Str::ascii()` alone loses house numbers: it maps Arabic-Indic `٢٧` but
 * drops Persian `۲۷` and Devanagari `२७` outright. Every Unicode decimal
 * digit is made its ASCII digit first, so a number survives whatever script
 * it was written in.
 */
final class LabelText
{
    public static function ascii(string $text): string
    {
        return Str::ascii(self::asciiDigits($text));
    }

    /**
     * Every Unicode decimal digit as its ASCII digit, and nothing else changed.
     */
    public static function asciiDigits(string $text): string
    {
        return (string) preg_replace_callback(
            '/\p{Nd}/u',
            fn (array $match): string => (string) IntlChar::charDigitValue($match[0]),
            $text,
        );
    }
}
