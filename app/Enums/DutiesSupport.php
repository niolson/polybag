<?php

namespace App\Enums;

/**
 * What a carrier can do with duties terms to one destination, as
 * `resources/data/customs/duties-support.json` records it (ADR-0008 decision 4).
 */
enum DutiesSupport: string
{
    /** The carrier ships there only with duties prepaid. */
    case DdpRequired = 'ddp_required';

    /** The carrier ships there on either term. */
    case Either = 'either';

    /** The carrier cannot prepay duties there. */
    case DduOnly = 'ddu_only';

    /**
     * The carrier cannot ship there on either term. Used by a registration
     * override when the carrier requires prepaid duties but cannot declare the
     * registration with them.
     */
    case Unavailable = 'unavailable';

    /**
     * Whether a rate under this support may be sold on the given term. A rate
     * that may not is dropped, never forced to the other term.
     */
    public function allows(DutiesTerms $terms): bool
    {
        return match ($this) {
            self::Either => true,
            self::DdpRequired => $terms === DutiesTerms::Ddp,
            self::DduOnly => $terms === DutiesTerms::Ddu,
            self::Unavailable => false,
        };
    }
}
