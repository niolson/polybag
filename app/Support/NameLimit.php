<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * A name cut to the length a carrier allows, and a record of the cut.
 *
 * Names are the one free-text field PolyBag still shortens for a carrier:
 * a shortened name on a label does not send a parcel somewhere else, while
 * a shortened street line or city can, and those are refused instead
 * (`release-polish/44`). The rule is fixed — the first $limit characters —
 * and every cut is logged on the carrier's channel beside its label request,
 * so what was sent can always be worked out. The log carries the field and
 * the lengths, not the name.
 */
final class NameLimit
{
    public static function cut(string $name, int $limit, string $logChannel, string $carrier, string $field): string
    {
        $name = trim($name);
        $length = mb_strlen($name);

        if ($length <= $limit) {
            return $name;
        }

        Log::channel($logChannel)->info("{$carrier} name cut to its {$limit}-character limit", [
            'field' => $field,
            'length' => $length,
            'limit' => $limit,
        ]);

        return mb_substr($name, 0, $limit);
    }
}
