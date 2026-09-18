<?php

namespace App\Enums;

/**
 * Why an offer could not be spent.
 *
 * Every case fails closed: the operator is sent back to a fresh quote rather
 * than into a purchase whose price, service or entitlement we can no longer
 * vouch for. ADR-0002 decision 4.
 */
enum OfferRejection: string
{
    /** No such offer — purged, or never issued under that identifier. */
    case NotFound = 'not_found';

    /** Real offer, wrong package. A quote is not transferable between parcels. */
    case WrongPackage = 'wrong_package';

    /** The source's window has closed; the price and promise are no longer good. */
    case Expired = 'expired';

    /** Already spent. Buying again would be a second purchase, not a retry. */
    case AlreadyConsumed = 'already_consumed';

    /**
     * Quoted in the other world. Sandbox and production identifiers differ, and
     * so do the hosts they are honoured by, so an offer outlives the toggle
     * only as a record — never as authority.
     */
    case EnvironmentChanged = 'environment_changed';

    /**
     * The package or its shipment was edited after the quote. The price was
     * for a different parcel — a different weight, box or address — and the
     * Ship page keys its rate cache on the same two timestamps for the same
     * reason, so the remedy is the re-quote it would have done anyway.
     */
    case PackageChanged = 'package_changed';

    public function title(): string
    {
        return match ($this) {
            self::NotFound, self::WrongPackage => 'Rate Unavailable',
            self::Expired => 'Rate Expired',
            self::AlreadyConsumed => 'Rate Already Used',
            self::EnvironmentChanged => 'Sandbox Mode Changed',
            self::PackageChanged => 'Package Changed',
        };
    }

    /**
     * Whether a fresh quote is the whole remedy.
     *
     * True for every rejection that is cured by asking again, which is all of
     * them but one. The Ship page acts on it by re-quoting on the spot, so the
     * packer is looking at a current list by the time they read the message;
     * the unattended paths have nobody to show a list to and simply fail, which
     * is why the wording above stays an instruction rather than a report.
     *
     * `AlreadyConsumed` is the exception, and not by omission: a label may
     * already exist for that offer, so quoting again is exactly what must not
     * happen automatically. Someone has to look first.
     */
    public function requiresRequote(): bool
    {
        return $this !== self::AlreadyConsumed;
    }

    /**
     * Wording aimed at a packer at the Ship page, who needs to know what to do
     * next rather than which invariant held.
     */
    public function message(): string
    {
        return match ($this) {
            self::NotFound => 'This rate is no longer on file. Get rates again and choose one.',
            self::WrongPackage => 'This rate was quoted for a different package. Get rates again for this one.',
            self::Expired => 'This rate has expired. Get rates again to buy at a current price.',
            self::AlreadyConsumed => 'This rate has already been used to buy a label. '
                .'Check the package for a tracking number before buying again — if there is none, get rates again.',
            self::EnvironmentChanged => 'Sandbox mode was switched after this rate was quoted, so it belongs to the '
                .'other environment. Get rates again.',
            self::PackageChanged => 'This package or its shipment was edited after this rate was quoted. '
                .'Get rates again for the package as it is now.',
        };
    }
}
