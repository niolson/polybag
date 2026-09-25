<?php

namespace App\Services\PostageSources;

use App\Filament\Pages\UnmappedObservedServices;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\ObservedService;
use App\Models\SourceServiceMapping;
use Illuminate\Support\Facades\DB;

/**
 * Normalization — the middle of ADR-0003 decision 2's three concepts.
 *
 * Observation records what a postage source said exists; this decides what we
 * call it. The two stay apart because `carrier_services.carrier_id` is a
 * non-nullable FK: the production `getRates` run returned OnTrac as the
 * cheapest eligible offer and we hold no `Carrier` row for it, so an observed
 * identity that could only exist as a `CarrierService` could not be recorded
 * at all.
 *
 * Nothing here runs off the back of a `getRates` response. Every method is
 * reached from a human pressing a button on {@see UnmappedObservedServices},
 * which is what "promotion creates canonical identities deliberately, or not at
 * all" means in practice. Leaving an identity unmapped forever is a valid
 * terminal state (decision 8), so there is no queue and no backfill.
 *
 * A decision is one {@see SourceServiceMapping} row, written or removed here
 * and nowhere else. Observations are never touched.
 */
class ObservedServiceMapper
{
    /**
     * Alias an observed identity onto a service we already have a row for.
     *
     * @return int observations the mapping now covers
     */
    public function map(ObservedService $observation, CarrierService $carrierService): int
    {
        $this->writeMapping($observation, $carrierService);

        return $this->coverage($observation);
    }

    /**
     * Author a `CarrierService` for an identity nothing in the catalog covers,
     * and alias the observation onto it.
     *
     * The `Carrier` is passed in already saved rather than authored here: for
     * OnTrac and the rest, creating it is its own deliberate act, taken through
     * the carrier select's create-option form. This method is the second half
     * of that, not a shortcut past it.
     *
     * @return int observations the mapping now covers
     */
    public function promote(
        ObservedService $observation,
        Carrier $carrier,
        string $serviceCode,
        string $serviceName,
        bool $canShipToPoBoxes = false,
        bool $canShipToMilitaryAddresses = false,
    ): int {
        DB::transaction(function () use (
            $observation,
            $carrier,
            $serviceCode,
            $serviceName,
            $canShipToPoBoxes,
            $canShipToMilitaryAddresses,
        ): void {
            $carrierService = CarrierService::create([
                'carrier_id' => $carrier->getKey(),
                'service_code' => $serviceCode,
                'name' => $serviceName,
                'active' => true,
                'can_ship_to_po_boxes' => $canShipToPoBoxes,
                'can_ship_to_military_addresses' => $canShipToMilitaryAddresses,
            ]);

            $this->writeMapping($observation, $carrierService);
        });

        return $this->coverage($observation);
    }

    /**
     * Return an identity to the unmapped state, which is a valid place for it
     * to stay.
     *
     * Approvals are left alone. What a service is called is not whether
     * automation may buy it (`amazon-buy-shipping/18`): an approval names the
     * source's own identifiers, which unmapping does not change, so withdrawing
     * it here would switch automation off as a side effect of a naming fix.
     *
     * @return int observations returned to unmapped
     */
    public function unmap(ObservedService $observation): int
    {
        SourceServiceMapping::query()
            ->forIdentity($observation->sourceKind(), $observation->external_carrier_id, $observation->external_service_id)
            ->delete();

        return $this->coverage($observation);
    }

    private function writeMapping(ObservedService $observation, CarrierService $carrierService): void
    {
        SourceServiceMapping::map(
            $observation->sourceKind(),
            $observation->external_carrier_id,
            $observation->external_service_id,
            $carrierService->getKey(),
        );
    }

    /**
     * How many observations one mapping names — one per environment and
     * marketplace the service has been seen in — so the page can say when a
     * decision reached more than the row it was made on.
     */
    private function coverage(ObservedService $observation): int
    {
        return ObservedService::query()
            ->sameService($observation->source, $observation->external_carrier_id, $observation->external_service_id)
            ->count();
    }
}
