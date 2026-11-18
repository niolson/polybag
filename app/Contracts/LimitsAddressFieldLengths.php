<?php

namespace App\Contracts;

use App\DataTransferObjects\Shipping\AddressData;
use App\DataTransferObjects\Shipping\AddressFieldTooLong;

/**
 * A carrier whose label request caps street lines and city at a length.
 *
 * Asked before the carrier is rated, so the Ship page says why it is missing
 * and automation never picks it, and again before a purchase is sent. The
 * adapter then sends those fields uncut: nothing is shortened or rewrapped to
 * fit (`release-polish/44`).
 */
interface LimitsAddressFieldLengths
{
    /**
     * The first street line or city of the label's addresses this carrier
     * cannot take as written, or null.
     */
    public function addressFieldTooLong(AddressData $from, AddressData $to): ?AddressFieldTooLong;
}
