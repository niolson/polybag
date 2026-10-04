<?php

namespace App\Services\PackSlips;

use App\DataTransferObjects\PackSlips\PackSlipRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Holds a chosen run briefly so a new browser tab can show it. The run travels as
 * a random key rather than as Shipment IDs in the URL, which a large selection
 * would make too long for the web server.
 */
class PackSlipViews
{
    public const int LIFETIME_SECONDS = 3600;

    public function put(PackSlipRun $run): string
    {
        $key = Str::random(40);

        Cache::put($this->cacheKey($key), $run->shipmentIds, self::LIFETIME_SECONDS);

        return $key;
    }

    /**
     * The run stored under the key, or null once it has expired.
     */
    public function get(string $key): ?PackSlipRun
    {
        $shipmentIds = Cache::get($this->cacheKey($key));

        return is_array($shipmentIds) ? new PackSlipRun(array_values($shipmentIds)) : null;
    }

    private function cacheKey(string $key): string
    {
        return 'pack-slip-view:'.$key;
    }
}
