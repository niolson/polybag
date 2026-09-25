<?php

namespace App\Services\Shipping;

use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\ContentClass;
use App\Models\CarrierService;
use App\Models\Package;
use Illuminate\Support\Collection;

/**
 * The one shared rule for services that require qualifying contents — ADR-0006
 * decisions 10 and 11.
 *
 * A rate is dropped when the `CarrierService` it names requires a content class
 * the Package does not qualify for ({@see Package::qualifiesFor()}). The
 * requirement is the service's, not the adapter's, so every source that sells
 * the service calls this rather than keeping its own list.
 *
 * Runs beside {@see PackagingFilter} wherever it runs: before the quote log in
 * `ShippingRateService::getShippingRates()`, so a rate never offered is never
 * logged or issued an Offer; and on a rule's pre-selected rate, which reaches
 * automation without passing through rate shopping. A rate that names no
 * catalog service carries no known requirement and passes.
 */
final class ContentsFilter
{
    /**
     * @param  Collection<int, RateResponse>  $rates
     * @param  list<ContentClass>  $qualifyingContents  What the Package qualifies for, from {@see Package::qualifyingContents()}.
     * @return Collection<int, RateResponse>
     */
    public static function keepQualifying(Collection $rates, array $qualifyingContents): Collection
    {
        $serviceIds = $rates->pluck('carrierServiceId')->filter()->unique()->values();

        if ($serviceIds->isEmpty()) {
            return $rates->values();
        }

        $requirements = CarrierService::query()
            ->whereIn('id', $serviceIds)
            ->whereNotNull('required_contents')
            ->get(['id', 'required_contents'])
            ->mapWithKeys(fn (CarrierService $service): array => [$service->id => $service->required_contents]);

        return $rates
            ->reject(function (RateResponse $rate) use ($requirements, $qualifyingContents): bool {
                $required = $requirements->get($rate->carrierServiceId);

                return $required instanceof ContentClass && ! in_array($required, $qualifyingContents, true);
            })
            ->values();
    }
}
