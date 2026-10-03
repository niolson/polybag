<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A pack slip receipt that cannot be redeemed: altered, expired, or issued to
 * another user.
 */
class InvalidPackSlipReceiptException extends RuntimeException {}
