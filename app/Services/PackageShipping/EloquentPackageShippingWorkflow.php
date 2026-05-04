<?php

namespace App\Services\PackageShipping;

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageAutoShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingOptions;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\PackageShipping\PackageShippingResult;
use App\DataTransferObjects\Shipping\ClassifiedRate;
use App\DataTransferObjects\Shipping\RateResponse;
use App\DataTransferObjects\Shipping\ShipRequest;
use App\Enums\PackageStatus;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use App\Services\RateQuoteLogger;
use App\Services\RateSelector;
use App\Services\RuleEvaluator;
use App\Services\ShippingRateService;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\Statuses\RequestTimeOutException;

class EloquentPackageShippingWorkflow implements PackageShippingWorkflow
{
    public function __construct(
        private readonly ShippingRateService $shippingRateService,
        private readonly RuleEvaluator $ruleEvaluator,
        private readonly RateSelector $rateSelector,
        private readonly RateQuoteLogger $rateQuoteLogger,
        private readonly CarrierRegistry $carrierRegistry,
    ) {}

    public function prepareRates(Package $package): PackageShippingOptions
    {
        $package->loadMissing(['shipment.shippingMethod']);

        $rates = $this->shippingRateService->getShippingRates($package->id);
        $exclusions = $this->shippingRateService->getExclusions();

        $ruleResult = $this->ruleEvaluator->evaluate($package->shipment);
        if ($ruleResult->shouldFilterRates()) {
            $rates = $rates->reject(
                fn (RateResponse $rate): bool => in_array($rate->serviceCode, $ruleResult->excludedServiceCodes, true)
            );
        }

        $deadline = $package->shipment->getDeliverByDate();
        $classified = $this->rateSelector->classify($rates, $deadline);

        $labels = [];
        $descriptions = [];
        $options = [];

        foreach ($classified as $key => $classifiedRate) {
            $labels[$key] = $classifiedRate->rate->formLabel();
            $description = $classifiedRate->rate->formDescription();
            if (! $classifiedRate->isOnTime) {
                $description .= ' — LATE';
            }
            $descriptions[$key] = $description;
            $options[$key] = $classifiedRate->rate->toArray();
        }

        return new PackageShippingOptions(
            rateOptions: $options,
            rateOptionLabels: $labels,
            rateOptionDescriptions: $descriptions,
            deliverByDate: $deadline?->format('D, M j'),
            allRatesLate: $deadline !== null && $classified->isNotEmpty() && $classified->every(fn (ClassifiedRate $cr): bool => ! $cr->isOnTime),
            exclusions: $exclusions,
            selectedRateIndex: $this->selectedRateIndex($options, $ruleResult->preSelectedRate ?? null),
        );
    }

    public function ship(Package $package, PackageShippingRequest $request): PackageShippingResult
    {
        $this->rateQuoteLogger->markSelected($package->id, $request->selectedRate);

        try {
            $adapter = $this->carrierRegistry->get($request->selectedRate->carrier);
            $shipRequest = ShipRequest::fromPackageAndRate(
                $package,
                $request->selectedRate,
                $request->labelFormat,
                $request->labelDpi,
            );

            if ($request->requireCustomsWeightOverride && $this->requiresCustomsWeightOverride($shipRequest, $request->overrideCustomsWeights)) {
                return PackageShippingResult::customsWeightOverrideRequired();
            }

            if ($request->overrideCustomsWeights) {
                $shipRequest = $shipRequest->withScaledCustomsWeights();
            }

            $response = $adapter->createShipment($shipRequest);

            if (! $response->success) {
                return PackageShippingResult::failed('Shipping Error', $response->errorMessage ?? 'Failed to create shipment.');
            }

            $package->markShipped($response, $request->userId);

            return PackageShippingResult::shipped($response, $request->selectedRate);
        } catch (RequestTimeOutException) {
            logger()->error('Carrier API timeout', [
                'carrier' => $request->selectedRate->carrier,
                'package_id' => $package->id,
            ]);

            return PackageShippingResult::failed(
                'Carrier Timeout',
                "The {$request->selectedRate->carrier} API is not responding. Please try again in a few moments.",
            );
        } catch (RequestException $e) {
            logger()->error('Carrier API error', [
                'carrier' => $request->selectedRate->carrier,
                'package_id' => $package->id,
                'error' => $e->getMessage(),
            ]);

            return PackageShippingResult::failed(
                'Carrier Error',
                "Unable to connect to {$request->selectedRate->carrier}. Please check your connection and try again.",
            );
        } catch (\RuntimeException $e) {
            return PackageShippingResult::stateConflict($e->getMessage());
        } catch (\Exception $e) {
            logger()->error('Shipping error', [
                'package_id' => $package->id,
                'error' => $e->getMessage(),
            ]);

            return PackageShippingResult::failed('Shipping Error', 'An unexpected error occurred. Please try again.');
        }
    }

    public function autoShip(Package $package, PackageAutoShippingRequest $request): PackageShippingResult
    {
        try {
            $selectedRate = $this->selectedRateForAutoShip($package);

            if (! $selectedRate) {
                $result = PackageShippingResult::failed('Shipping Error', 'No shipping rates available for this package.');
                $this->cleanupPackage($package, $request, $result);

                return $result;
            }

            $result = $this->ship(
                $package,
                new PackageShippingRequest(
                    selectedRate: $selectedRate,
                    labelFormat: $request->labelFormat,
                    labelDpi: $request->labelDpi,
                    requireCustomsWeightOverride: false,
                    userId: $request->userId,
                ),
            );

            $this->cleanupPackage($package, $request, $result);

            return $result;
        } catch (RequestTimeOutException) {
            logger()->error('AutoShip timeout', ['package_id' => $package->id]);
            $result = PackageShippingResult::failed('Carrier Timeout', 'The carrier API is not responding. Please try again in a few moments.');
            $this->cleanupPackage($package, $request, $result);

            return $result;
        } catch (RequestException $e) {
            logger()->error('AutoShip carrier error', ['package_id' => $package->id, 'error' => $e->getMessage()]);
            $result = PackageShippingResult::failed('Carrier Error', 'Unable to connect to the carrier. Please try again.');
            $this->cleanupPackage($package, $request, $result);

            return $result;
        } catch (\RuntimeException $e) {
            logger()->warning('AutoShip race condition', ['package_id' => $package->id, 'error' => $e->getMessage()]);

            return PackageShippingResult::stateConflict($e->getMessage());
        } catch (\Exception $e) {
            logger()->error('AutoShip error', ['package_id' => $package->id, 'error' => $e->getMessage()]);
            $result = PackageShippingResult::failed('Auto Ship Error', 'An unexpected error occurred. Please try again.');
            $this->cleanupPackage($package, $request, $result);

            return $result;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rateOptions
     */
    private function selectedRateIndex(array $rateOptions, ?RateResponse $preSelectedRate): ?int
    {
        if (! $preSelectedRate) {
            return $rateOptions === [] ? null : 0;
        }

        foreach ($rateOptions as $key => $rateArray) {
            if ($rateArray['carrier'] === $preSelectedRate->carrier && $rateArray['serviceCode'] === $preSelectedRate->serviceCode) {
                return $key;
            }
        }

        return $rateOptions === [] ? null : 0;
    }

    private function requiresCustomsWeightOverride(ShipRequest $shipRequest, bool $overrideCustomsWeights): bool
    {
        if ($overrideCustomsWeights || $shipRequest->toAddress->country === 'US' || empty($shipRequest->customsItems)) {
            return false;
        }

        $totalCustomsWeight = collect($shipRequest->customsItems)->sum(fn ($item): float => $item->weight * $item->quantity);

        return $totalCustomsWeight > $shipRequest->packageData->weight;
    }

    private function selectedRateForAutoShip(Package $package): ?RateResponse
    {
        $package->loadMissing(['packageItems.product', 'packageItems.shipmentItem', 'shipment.shippingMethod']);

        $ruleResult = $this->ruleEvaluator->evaluate($package->shipment, $package);

        if ($ruleResult->hasPreSelectedRate()) {
            $adapter = $this->carrierRegistry->get($ruleResult->preSelectedRate->carrier);

            return $adapter->resolvePreSelectedRate($ruleResult->preSelectedRate, $package);
        }

        $options = $this->prepareRates($package);

        if ($options->selectedRateIndex === null || ! isset($options->rateOptions[$options->selectedRateIndex])) {
            return null;
        }

        return RateResponse::fromArray($options->rateOptions[$options->selectedRateIndex]);
    }

    private function cleanupPackage(Package $package, PackageAutoShippingRequest $request, PackageShippingResult $result): void
    {
        if (! $request->cleanupOnFailure || $result->success || $result->leavePackageIntact) {
            return;
        }

        if ($package->exists && $package->status !== PackageStatus::Shipped) {
            $package->packageItems()->delete();
            $package->delete();
        }
    }
}
