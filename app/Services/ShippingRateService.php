<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\RateRequest;
use App\Exceptions\NoActiveCarrierServicesException;
use App\Models\CarrierService;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use Illuminate\Support\Collection;

class ShippingRateService
{
    /**
     * Get shipping rates for a package from all applicable carriers.
     *
     * @return Collection<int, \App\DataTransferObjects\Shipping\RateResponse>
     *
     * @throws NoActiveCarrierServicesException
     */
    public static function getShippingRates(int $packageId): Collection
    {
        $package = Package::with(['packageItems', 'shipment.shippingMethod'])
            ->findOrFail($packageId);

        $shipment = $package->shipment;
        $shippingMethod = $shipment->shippingMethod;
        $rateRequest = RateRequest::fromPackage($package);
        $rateOptions = collect();

        if ($shippingMethod) {
            $activeCarrierServices = self::getActiveCarrierServices($shippingMethod);

            if ($activeCarrierServices->isEmpty()) {
                throw new NoActiveCarrierServicesException($shippingMethod->name);
            }

            logger()->debug('ShippingRateService: Getting rates', [
                'package_id' => $packageId,
                'shipping_method' => $shippingMethod->name,
                'active_carrier_services_count' => $activeCarrierServices->count(),
                'carrier_services' => $activeCarrierServices->pluck('service_code', 'name')->toArray(),
            ]);

            $carrierServicesByCarrier = $activeCarrierServices->groupBy('carrier_id');

            foreach ($carrierServicesByCarrier as $services) {
                $carrier = $services->first()->carrier;
                $serviceCodes = $services->pluck('service_code')->toArray();

                self::fetchCarrierRates($carrier->name, $rateRequest, $serviceCodes, $rateOptions);
            }
        } else {
            logger()->debug('ShippingRateService: No shipping method assigned, querying all configured carriers', [
                'package_id' => $packageId,
            ]);

            foreach (CarrierRegistry::getConfiguredAdapters() as $name => $adapter) {
                self::fetchCarrierRates($name, $rateRequest, [], $rateOptions);
            }
        }

        return $rateOptions;
    }

    /**
     * Get active carrier services for a shipping method.
     * Filters to only include services where both the carrier and the service are active.
     *
     * @return Collection<int, CarrierService>
     */
    private static function getActiveCarrierServices(\App\Models\ShippingMethod $shippingMethod): Collection
    {
        return $shippingMethod->carrierServices()
            ->active()
            ->withActiveCarrier()
            ->with('carrier')
            ->get();
    }

    /**
     * Fetch rates from a single carrier and append to the rate options collection.
     *
     * @param  array<string>  $serviceCodes
     * @param  Collection<int, \App\DataTransferObjects\Shipping\RateResponse>  $rateOptions
     */
    private static function fetchCarrierRates(string $carrierName, RateRequest $rateRequest, array $serviceCodes, Collection $rateOptions): void
    {
        logger()->debug("ShippingRateService: Fetching rates from {$carrierName}", [
            'service_codes' => $serviceCodes,
        ]);

        try {
            if (! CarrierRegistry::has($carrierName)) {
                logger()->warning("ShippingRateService: Unknown carrier {$carrierName}");

                return;
            }

            $adapter = CarrierRegistry::get($carrierName);

            if (! $adapter->isConfigured()) {
                logger()->warning("ShippingRateService: {$carrierName} is not configured");

                return;
            }

            $rates = $adapter->getRates($rateRequest, $serviceCodes);

            logger()->debug("ShippingRateService: Got {$carrierName} rates", [
                'rates_count' => $rates->count(),
            ]);

            $rateOptions->push(...$rates);
        } catch (\Exception $e) {
            logger()->error("ShippingRateService: {$carrierName} error", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
