<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\LabelResult;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LabelGenerationService
{
    /**
     * Generate a label for a package using the best available rate.
     *
     * Does NOT call markShipped() — the caller handles that,
     * since cleanup behavior differs between autoShip, batch, etc.
     */
    public function generateLabel(
        Package $package,
        string $labelFormat = 'pdf',
        ?int $labelDpi = null,
    ): LabelResult {
        $package->loadMissing(['packageItems.product', 'packageItems.shipmentItem', 'shipment.shippingMethod']);

        $ruleResult = app(RuleEvaluator::class)->evaluate($package->shipment, $package);

        if ($ruleResult->hasPreSelectedRate()) {
            $adapter = app(CarrierRegistry::class)->get($ruleResult->preSelectedRate->carrier);
            $selectedRate = $adapter->resolvePreSelectedRate($ruleResult->preSelectedRate, $package);
        } else {
            $rates = app(ShippingRateService::class)->getShippingRates($package->id);

            if ($ruleResult->shouldFilterRates()) {
                $rates = $rates->reject(
                    fn (RateResponse $rate) => in_array($rate->serviceCode, $ruleResult->excludedServiceCodes)
                );
            }

            if ($rates->isEmpty()) {
                return LabelResult::failure('No shipping rates available for this package.');
            }

            $deadline = $package->shipment->getDeliverByDate();
            $selectedRate = $this->selectBestRate($rates, $deadline);

            app(RateQuoteLogger::class)->markSelected($package->id, $selectedRate);
        }

        $adapter = app(CarrierRegistry::class)->get($selectedRate->carrier);
        $shipRequest = ShipRequest::fromPackageAndRate($package, $selectedRate, $labelFormat, $labelDpi);
        $response = $adapter->createShipment($shipRequest);

        if (! $response->success) {
            return LabelResult::failure($response->errorMessage ?? 'Failed to create shipment.');
        }

        return LabelResult::success($response, $selectedRate);
    }

    /**
     * Select the best rate: cheapest on-time rate if a deadline exists,
     * otherwise cheapest overall.
     *
     * @param  Collection<int, RateResponse>  $rates
     */
    private function selectBestRate(Collection $rates, ?Carbon $deadline): RateResponse
    {
        if (! $deadline) {
            return $rates->sortBy('price')->first();
        }

        $onTime = $rates->filter(function (RateResponse $rate) use ($deadline) {
            $deliveryDate = $rate->parsedDeliveryDate();

            // If no delivery date, treat as uncertain — don't prefer it
            return $deliveryDate && $deliveryDate->lte($deadline);
        });

        if ($onTime->isNotEmpty()) {
            return $onTime->sortBy('price')->first();
        }

        // All rates are late or unknown — pick cheapest overall
        return $rates->sortBy('price')->first();
    }
}
