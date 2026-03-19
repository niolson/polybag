<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\AutoShipResult;
use App\DataTransferObjects\Shipping\LabelResult;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\PackageStatus;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Collection;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\Statuses\RequestTimeOutException;

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
     * Generate a label and mark the package as shipped.
     *
     * Handles the full ship-or-rollback lifecycle:
     * 1. Generate label via carrier API
     * 2. Mark package shipped on success
     * 3. Delete package (and call $onCleanup) on failure
     *
     * @param  Package  $package  The package to ship
     * @param  string  $labelFormat  'pdf' or 'zpl'
     * @param  int|null  $labelDpi  DPI for ZPL labels
     * @param  int|null  $userId  User ID to record as shipper
     * @param  Closure|null  $onCleanup  Called after package deletion on failure (e.g. to delete shipment)
     */
    public function autoShip(
        Package $package,
        string $labelFormat = 'pdf',
        ?int $labelDpi = null,
        ?int $userId = null,
        ?Closure $onCleanup = null,
    ): AutoShipResult {
        try {
            $result = $this->generateLabel($package, $labelFormat, $labelDpi);

            if (! $result->success) {
                $this->cleanupPackage($package, $onCleanup);

                return AutoShipResult::failed('Shipping Error', $result->errorMessage);
            }

            $package->markShipped($result->response, $userId);

            return AutoShipResult::shipped($result->response, $result->selectedRate);

        } catch (RequestTimeOutException $e) {
            $this->cleanupPackage($package, $onCleanup);
            logger()->error('AutoShip timeout', ['package_id' => $package->id]);

            return AutoShipResult::failed('Carrier Timeout', 'The carrier API is not responding. Please try again in a few moments.');

        } catch (RequestException $e) {
            $this->cleanupPackage($package, $onCleanup);
            logger()->error('AutoShip carrier error', ['package_id' => $package->id, 'error' => $e->getMessage()]);

            return AutoShipResult::failed('Carrier Error', 'Unable to connect to the carrier. Please try again.');

        } catch (\RuntimeException $e) {
            // Optimistic locking failure — don't delete, just report
            logger()->warning('AutoShip race condition', ['package_id' => $package->id, 'error' => $e->getMessage()]);

            return AutoShipResult::failed('Package State Changed', $e->getMessage());

        } catch (\Exception $e) {
            $this->cleanupPackage($package, $onCleanup);
            logger()->error('AutoShip error', ['package_id' => $package->id, 'error' => $e->getMessage()]);

            return AutoShipResult::failed('Auto Ship Error', 'An unexpected error occurred. Please try again.');
        }
    }

    /**
     * Delete a package and its items if it hasn't been shipped.
     * Calls the optional cleanup callback after deletion (e.g. to delete a shipment).
     */
    private function cleanupPackage(Package $package, ?Closure $onCleanup): void
    {
        if ($package->exists && $package->status !== PackageStatus::Shipped) {
            $package->packageItems()->delete();
            $package->delete();
            if ($onCleanup) {
                $onCleanup();
            }
        }
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
