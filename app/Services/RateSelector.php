<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\ClassifiedRate;
use App\DataTransferObjects\Shipping\RateResponse;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RateSelector
{
    /**
     * Classify and sort rates into on-time then late, each group sorted cheapest first.
     * "On-time" requires a known delivery date on or before the deadline.
     * Unknown delivery date with a deadline counts as late.
     * No deadline = all rates classified as on-time.
     *
     * @param  Collection<int, RateResponse>  $rates
     * @return Collection<int, ClassifiedRate>
     */
    public function classify(Collection $rates, ?Carbon $deadline): Collection
    {
        $classified = $rates->map(
            fn (RateResponse $rate) => new ClassifiedRate(
                rate: $rate,
                isOnTime: $this->isOnTime($rate, $deadline),
            )
        );

        $onTime = $classified
            ->filter(fn (ClassifiedRate $cr) => $cr->isOnTime)
            ->sortBy(fn (ClassifiedRate $cr) => $cr->rate->price);

        $late = $classified
            ->filter(fn (ClassifiedRate $cr) => ! $cr->isOnTime)
            ->sortBy(fn (ClassifiedRate $cr) => $cr->rate->price);

        return $onTime->merge($late)->values();
    }

    /**
     * Select the best rate: cheapest on-time when a deadline exists, otherwise cheapest overall.
     *
     * @param  Collection<int, RateResponse>  $rates
     */
    public function selectBest(Collection $rates, ?Carbon $deadline): RateResponse
    {
        return $this->classify($rates, $deadline)->first()->rate;
    }

    private function isOnTime(RateResponse $rate, ?Carbon $deadline): bool
    {
        if (! $deadline) {
            return true;
        }

        $deliveryDate = $rate->parsedDeliveryDate();

        return $deliveryDate !== null && $deliveryDate->lte($deadline);
    }
}
