<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A by-hand resolution of an unaccounted purchase that cannot be recorded as
 * asked — `postage-source-split/16`. The message is shown to the operator.
 */
class PurchaseNotResolvableException extends RuntimeException
{
    public static function alreadyResolved(): self
    {
        return new self('This purchase has already been resolved. Reload the page to see where it stands.');
    }

    public static function missingTrackingNumber(): self
    {
        return new self('Enter the tracking number of the label that was bought.');
    }

    public static function sourceConfirmedSale(): self
    {
        return new self('The carrier or channel confirmed it sold this label, or said the label exists, so it cannot be recorded as nothing bought. Void or keep the label instead.');
    }

    public static function connectionGone(): self
    {
        return new self('The connection this label was bought through no longer exists, so the label could not be voided or tracked from here. Restore the connection before recording the label.');
    }

    public static function purchaseInProgress(): self
    {
        return new self('Postage for this package is being bought or checked right now. Wait for that to finish, then try again.');
    }

    public static function packageAlreadyShipped(): self
    {
        return new self('This package already has an active label. Void it first, or record this purchase as nothing bought.');
    }
}
