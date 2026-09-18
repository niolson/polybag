<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\RateResponse;
use App\Models\RateQuote;
use App\Models\ShippingOffer;
use Illuminate\Support\Collection;

/**
 * The analytics log of what a package was quoted — `rate_quotes`.
 *
 * One row per rate ever offered, kept on a long retention so that *Rate
 * Comparison* can answer what the other options would have cost. It is a log,
 * not purchase authority: the offer store holds that, and points at its row
 * here through {@see ShippingOffer::$rate_quote_id}.
 */
class RateQuoteLogger
{
    /**
     * Log all rate quotes for a package.
     *
     * Returns the ids in the order of the rates given, so the caller can tie
     * each offer it issues to the row logged for the same rate. Inserted one
     * at a time for that reason — a bulk insert reports nothing back — and
     * cheap enough, since a package is quoted a handful of rates.
     *
     * @param  Collection<int, RateResponse>  $rates
     * @return list<int>
     */
    public function logRates(int $packageId, Collection $rates): array
    {
        if ($rates->isEmpty()) {
            return [];
        }

        $now = now();

        return $rates->values()->map(fn (RateResponse $rate): int => RateQuote::query()->insertGetId([
            'package_id' => $packageId,
            'carrier' => $rate->carrier,
            'service_code' => $rate->serviceCode,
            'service_name' => $rate->serviceName,
            'quoted_price' => $rate->price,
            'quoted_delivery_date' => $rate->deliveryDate,
            'transit_time' => $rate->transitTime,
            'selected' => false,
            'created_at' => $now,
        ]))->all();
    }

    /**
     * Mark the quote a purchase was made from.
     *
     * By primary key, through the offer that was bought. Matching on carrier
     * and service code — what this did before offers pointed at their quote —
     * could not tell two USPS variants of one mail class apart and marked
     * both, and would have marked a direct quote and its channel-resold twin
     * together. An offer with no quote behind it — a rule's pre-selection,
     * which never rate-shopped — has nothing to mark; that gap is
     * `postage-source-split/17`.
     */
    public function markSelected(ShippingOffer $offer): void
    {
        if ($offer->rate_quote_id === null) {
            return;
        }

        RateQuote::query()
            ->whereKey($offer->rate_quote_id)
            ->update(['selected' => true]);
    }
}
