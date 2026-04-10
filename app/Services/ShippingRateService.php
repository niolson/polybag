<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\PreparedRateRequest;
use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\ServiceCapability;
use App\Exceptions\NoActiveCarrierServicesException;
use App\Models\CarrierService;
use App\Models\Package;
use App\Models\ShippingMethod;
use App\Models\SpecialService;
use App\Services\Carriers\CarrierRegistry;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Illuminate\Support\Collection;
use Saloon\Http\Senders\GuzzleSender;

class ShippingRateService
{
    /**
     * Carriers excluded from the last getShippingRates() call due to prohibited services.
     * Keyed by carrier name, value is the human-readable reason.
     *
     * @var array<string, string>
     */
    private array $exclusions = [];

    /**
     * Returns carriers excluded from the last getShippingRates() call.
     * Each entry is ['carrier' => string, 'reason' => string].
     *
     * @return array<int, array{carrier: string, reason: string}>
     */
    public function getExclusions(): array
    {
        return array_map(
            fn ($carrier, $reason) => ['carrier' => $carrier, 'reason' => $reason],
            array_keys($this->exclusions),
            $this->exclusions,
        );
    }

    /**
     * Get shipping rates for a package from all applicable carriers.
     *
     * @return Collection<int, RateResponse>
     *
     * @throws NoActiveCarrierServicesException
     */
    public function getShippingRates(int $packageId): Collection
    {
        $package = Package::with(['packageItems', 'shipment.shippingMethod'])
            ->findOrFail($packageId);

        $shipment = $package->shipment;
        $shippingMethod = $shipment->shippingMethod;
        $rateRequest = RateRequest::fromPackage($package);

        // Build the list of carriers to query
        $carrierTasks = [];

        if ($shippingMethod) {
            $activeCarrierServices = $this->getActiveCarrierServices($shippingMethod);

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
                $carrierTasks[] = ['name' => $carrier->name, 'serviceCodes' => $serviceCodes];
            }
        } else {
            logger()->debug('ShippingRateService: No shipping method assigned, querying all configured carriers', [
                'package_id' => $packageId,
            ]);

            foreach (app(CarrierRegistry::class)->getConfiguredAdapters() as $name => $adapter) {
                $carrierTasks[] = ['name' => $name, 'serviceCodes' => []];
            }
        }

        $this->exclusions = [];
        $requiredServiceCodes = $this->getRequiredServiceCodes($shippingMethod ?? null);
        $carrierTasks = $this->filterProhibitedCarriers($carrierTasks, $requiredServiceCodes);

        $rateOptions = $this->fetchRatesConcurrently($carrierTasks, $rateRequest);

        try {
            app(RateQuoteLogger::class)->logRates($packageId, $rateOptions);
        } catch (\Exception $e) {
            logger()->warning('Failed to log rate quotes', [
                'package_id' => $packageId,
                'error' => $e->getMessage(),
            ]);
        }

        return $rateOptions;
    }

    /**
     * Fetch rates from multiple carriers concurrently using a shared Guzzle sender.
     *
     * @param  array<int, array{name: string, serviceCodes: array<string>}>  $carrierTasks
     * @return Collection<int, RateResponse>
     */
    private function fetchRatesConcurrently(array $carrierTasks, RateRequest $rateRequest): Collection
    {
        $rateOptions = collect();
        $preparedRequests = [];
        $taskMeta = [];

        $registry = app(CarrierRegistry::class);

        $shipDateService = app(ShipDateService::class);

        // Phase 1: Prepare all requests (authenticate connectors, build request bodies)
        foreach ($carrierTasks as $task) {
            $carrierName = $task['name'];
            $serviceCodes = $task['serviceCodes'];

            try {
                if (! $registry->has($carrierName)) {
                    logger()->warning("ShippingRateService: Unknown carrier {$carrierName}");

                    continue;
                }

                $adapter = $registry->get($carrierName);

                if (! $adapter->isConfigured()) {
                    logger()->warning("ShippingRateService: {$carrierName} is not configured");

                    continue;
                }

                $shipDate = $shipDateService->getShipDate($carrierName, $rateRequest->locationId);
                $carrierRateRequest = $rateRequest->withShipDate($shipDate);

                $prepared = $adapter->prepareRateRequest($carrierRateRequest, $serviceCodes);

                if (! $prepared) {
                    // No API call needed — fall back to synchronous getRates (e.g., mock rates)
                    $rates = $adapter->getRates($carrierRateRequest, $serviceCodes);
                    $rateOptions->push(...$rates);

                    continue;
                }

                $preparedRequests[$carrierName] = $prepared;
                $taskMeta[$carrierName] = ['adapter' => $adapter, 'serviceCodes' => $serviceCodes, 'rateRequest' => $carrierRateRequest];
            } catch (\Exception $e) {
                logger()->error("ShippingRateService: {$carrierName} prepare error", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fall back to synchronous sends when only one carrier needs an API call (no
        // concurrency overhead needed) or when any request has a fake/mock response set
        // (Saloon faking bypasses the sender, so the shared GuzzleSender can't handle it).
        $hasFakeResponses = collect($preparedRequests)->contains(
            fn (PreparedRateRequest $p) => $p->pendingRequest->hasFakeResponse()
        );

        if (count($preparedRequests) <= 1 || $hasFakeResponses) {
            foreach ($preparedRequests as $carrierName => $prepared) {
                $meta = $taskMeta[$carrierName];
                $rates = $meta['adapter']->getRates($meta['rateRequest'], $meta['serviceCodes']);
                $rateOptions->push(...$rates);
            }

            return $rateOptions;
        }

        // Phase 2: Send all requests concurrently through a shared Guzzle sender
        $sharedSender = new GuzzleSender;
        $promises = [];

        foreach ($preparedRequests as $carrierName => $prepared) {
            logger()->debug("ShippingRateService: Sending async rate request to {$carrierName}");
            $promises[$carrierName] = $sharedSender->sendAsync($prepared->pendingRequest);
        }

        $results = PromiseUtils::settle($promises)->wait();

        // Phase 3: Parse responses
        foreach ($results as $carrierName => $result) {
            $meta = $taskMeta[$carrierName];

            if ($result['state'] === 'fulfilled') {
                try {
                    $rates = $meta['adapter']->parseRateResponse(
                        $result['value'],
                        $meta['rateRequest'],
                        $meta['serviceCodes'],
                    );

                    logger()->debug("ShippingRateService: Got {$carrierName} rates", [
                        'rates_count' => $rates->count(),
                    ]);

                    $rateOptions->push(...$rates);
                } catch (\Exception $e) {
                    logger()->error("ShippingRateService: {$carrierName} parse error", [
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                logger()->error("ShippingRateService: {$carrierName} request failed", [
                    'error' => $result['reason']?->getMessage() ?? 'Unknown error',
                ]);
            }
        }

        return $rateOptions;
    }

    /**
     * Get the special service codes that are required or defaulted on a shipping method.
     * These are the services that will always be applied to packages using this method,
     * so they must be checked against each carrier's capabilities.
     *
     * @return array<string>
     */
    private function getRequiredServiceCodes(?ShippingMethod $shippingMethod): array
    {
        if (! $shippingMethod) {
            return [];
        }

        return $shippingMethod->specialServices()
            ->whereIn('shipping_method_special_service.mode', ['required', 'default'])
            ->pluck('code')
            ->all();
    }

    /**
     * Filter out carrier tasks where the carrier prohibits any of the required services.
     * Records the reason for each excluded carrier in $this->exclusions.
     *
     * @param  array<int, array{name: string, serviceCodes: array<string>}>  $carrierTasks
     * @param  array<string>  $requiredServiceCodes
     * @return array<int, array{name: string, serviceCodes: array<string>}>
     */
    private function filterProhibitedCarriers(array $carrierTasks, array $requiredServiceCodes): array
    {
        if (empty($requiredServiceCodes)) {
            return $carrierTasks;
        }

        $registry = app(CarrierRegistry::class);
        $serviceNames = SpecialService::whereIn('code', $requiredServiceCodes)
            ->pluck('name', 'code');

        return array_values(array_filter($carrierTasks, function (array $task) use ($registry, $requiredServiceCodes, $serviceNames): bool {
            $carrierName = $task['name'];

            if (! $registry->has($carrierName)) {
                return true;
            }

            $adapter = $registry->get($carrierName);

            foreach ($requiredServiceCodes as $code) {
                if ($adapter->serviceCapability($code) === ServiceCapability::Prohibited) {
                    $this->exclusions[$carrierName] = $carrierName.' does not support '.$serviceNames->get($code, $code).'.';

                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Get active carrier services for a shipping method.
     * Filters to only include services where both the carrier and the service are active.
     *
     * @return Collection<int, CarrierService>
     */
    private function getActiveCarrierServices(ShippingMethod $shippingMethod): Collection
    {
        return $shippingMethod->carrierServices()
            ->active()
            ->withActiveCarrier()
            ->with('carrier')
            ->get();
    }
}
