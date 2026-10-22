<?php

namespace App\Enums;

enum PackageExportStatus: string
{
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case RetryableFailed = 'retryable_failed';
    case PermanentlyFailed = 'permanently_failed';

    /**
     * Deliberately not sent: in Demo, PolyBag writes nothing to a live store
     * (`live-store-demo/01`). Settled like a success, so no retry picks it up,
     * and not a failure, so nothing reports one.
     */
    case Skipped = 'skipped';

    /**
     * The statuses that leave nothing more to send to a destination.
     *
     * @return list<self>
     */
    public static function settled(): array
    {
        return [self::Succeeded, self::Skipped];
    }
}
