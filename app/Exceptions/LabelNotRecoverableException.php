<?php

namespace App\Exceptions;

use App\Contracts\RecoversUnresolvedPurchase;
use RuntimeException;

/**
 * The source says a label exists for the purchase it was asked about, and
 * that it will never hand it over again — USPS refuses a reprint past the
 * label's mailing date (`160981`).
 *
 * The fourth answer to {@see RecoversUnresolvedPurchase::recoverPurchase()}:
 * not "nothing was bought", since money was spent, and not "still unknown",
 * since asking again cannot help. A person has to record the label and void
 * it if it should not be used.
 */
class LabelNotRecoverableException extends RuntimeException
{
    /**
     * @param  string  $sourceReason  What the source said, in its own words
     */
    public function __construct(public readonly string $sourceReason)
    {
        parent::__construct($sourceReason);
    }
}
