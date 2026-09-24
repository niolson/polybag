<?php

namespace App\Services\Carriers\Concerns;

use App\DataTransferObjects\Shipping\RateResponse;
use App\Models\Carrier;
use App\Models\CarrierService;
use Illuminate\Support\Collection;

/**
 * Names the catalog service and carrier on every rate a direct adapter
 * returns — `carrier-catalog-reset/02`.
 *
 * A direct adapter quotes its own carrier's codes, so it is the one party that
 * can say which `CarrierService` a rate is for without anyone downstream
 * looking it up by carrier name and code string. Applied to the rates as they
 * leave the adapter, so the rate service, a rule's pre-selected variant and a
 * test all see the same ids.
 *
 * The carrier row is found the way {@see ResolvesCarrierAccount} finds it, by
 * the adapter's declared name, until `carriers.adapter` replaces the name
 * (`05`). A code the catalog does not hold, such as a mail class quoted with
 * no shipping method, keeps the carrier and names no service. Where two rows
 * share a code, the older one wins, which is the seeded one.
 */
trait IdentifiesCatalogServices
{
    abstract public function getCarrierName(): string;

    /**
     * @param  Collection<int, RateResponse>  $rates
     * @return Collection<int, RateResponse>
     */
    private function withCatalogIdentity(Collection $rates): Collection
    {
        if ($rates->isEmpty()) {
            return $rates;
        }

        $carrierId = Carrier::where('name', $this->getCarrierName())->value('id');

        if ($carrierId === null) {
            return $rates;
        }

        $serviceIds = CarrierService::query()
            ->where('carrier_id', $carrierId)
            ->whereIn('service_code', $rates->pluck('serviceCode')->unique()->values())
            ->orderBy('id')
            ->get(['id', 'service_code'])
            ->unique('service_code')
            ->mapWithKeys(fn (CarrierService $service): array => [$service->service_code => $service->id]);

        return $rates->map(fn (RateResponse $rate): RateResponse => $rate->withCatalogIdentity(
            (int) $carrierId,
            $serviceIds->get($rate->serviceCode),
        ));
    }
}
