<?php

namespace App\DataTransferObjects\Shipping;

use App\Support\LabelText;

/**
 * An address field longer than a carrier takes (`release-polish/44`).
 *
 * PolyBag refuses such a label rather than cut or rewrap the field: what a
 * carrier is sent must be the address on the record, so a misshipment can be
 * traced to exactly what was sent.
 */
readonly class AddressFieldTooLong
{
    public function __construct(
        public string $carrier,
        public bool $isShipFrom,
        public string $field,
        public int $length,
        public int $limit,
    ) {}

    /**
     * The first street line or city of $address over its limit, or null.
     *
     * A field is measured as entered and as ASCII, and the longer counts:
     * depending on the label format a carrier is sent one or the other, and
     * transliteration can lengthen a line (`ß` becomes `ss`). Measuring both
     * gives the Ship page and the purchase the same answer whatever the
     * format.
     */
    public static function first(
        string $carrier,
        AddressData $address,
        bool $isShipFrom,
        int $streetLineLimit,
        int $cityLimit,
    ): ?self {
        $fields = [
            'address line 1' => [$address->streetAddress, $streetLineLimit],
            'address line 2' => [$address->streetAddress2, $streetLineLimit],
            'city' => [$address->city, $cityLimit],
        ];

        foreach ($fields as $field => [$text, $limit]) {
            $length = self::lengthOf($text);

            if ($length > $limit) {
                return new self($carrier, $isShipFrom, $field, $length, $limit);
            }
        }

        return null;
    }

    public function message(): string
    {
        $field = $this->isShipFrom ? "The ship-from {$this->field}" : ucfirst($this->field);

        $remedy = $this->isShipFrom
            ? 'Ask an administrator to shorten it on the Location.'
            : 'Ask a manager to shorten it on the Shipment; common abbreviations such as ST, AVE and APT usually do it.';

        return "{$field} is {$this->length} characters, and {$this->carrier} takes {$this->limit}. {$remedy}";
    }

    private static function lengthOf(?string $text): int
    {
        if (blank($text)) {
            return 0;
        }

        $text = trim($text);

        return max(mb_strlen($text), mb_strlen(LabelText::ascii($text)));
    }
}
