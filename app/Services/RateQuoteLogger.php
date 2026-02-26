<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\RateResponse;
use App\Models\RateQuote;
use Illuminate\Support\Collection;

class RateQuoteLogger
{
    /**
     * Log all rate quotes for a package.
     *
     * @param  Collection<int, RateResponse>  $rates
     */
    public function logRates(int $packageId, Collection $rates): void
    {
        if ($rates->isEmpty()) {
            return;
        }

        $now = now();

        $rows = $rates->map(fn (RateResponse $rate) => [
            'package_id' => $packageId,
            'carrier' => $rate->carrier,
            'service_code' => $rate->serviceCode,
            'service_name' => $rate->serviceName,
            'quoted_price' => $rate->price,
            'quoted_delivery_date' => $rate->deliveryDate,
            'transit_time' => $rate->transitTime,
            'selected' => false,
            'created_at' => $now,
        ])->all();

        RateQuote::insert($rows);
    }

    /**
     * Mark the selected rate quote for a package.
     */
    public function markSelected(int $packageId, RateResponse $selectedRate): void
    {
        RateQuote::where('package_id', $packageId)
            ->where('carrier', $selectedRate->carrier)
            ->where('service_code', $selectedRate->serviceCode)
            ->update(['selected' => true]);
    }
}
