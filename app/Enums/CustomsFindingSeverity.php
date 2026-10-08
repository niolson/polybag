<?php

namespace App\Enums;

use App\DataTransferObjects\Customs\CustomsFinding;

/**
 * What a {@see CustomsFinding} does to a
 * purchase: a block refuses it, a warning is shown and the purchase goes on.
 */
enum CustomsFindingSeverity: string
{
    case Block = 'block';
    case Warn = 'warn';
}
