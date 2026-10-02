<?php

namespace App\Services\PackageShipping;

use App\Models\Package;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * The one line a package's postage passes through at a time.
 *
 * Held by every purchase, by asking a source about an earlier one, and by a
 * person settling one by hand (`postage-source-split/16`): settling a purchase
 * that is still in flight as "nothing bought" would let the next attempt buy
 * a second label once the first one lands.
 */
final class PurchaseLock
{
    /**
     * How long one package's purchase may hold the line before the lock is
     * assumed abandoned. Generous on purpose: it spans an external label call,
     * and a lock that expires mid-purchase is worse than one held too long.
     */
    public const SECONDS = 180;

    public static function for(Package $package): Lock
    {
        return Cache::lock("package-purchase:{$package->id}", self::SECONDS);
    }
}
