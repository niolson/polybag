<?php

namespace App\Services;

use App\Models\BoxSize;
use App\Models\Carrier;
use App\Models\CarrierService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Centralized caching service for frequently accessed configuration data.
 */
class CacheService
{
    private const BOX_SIZES_KEY = 'box_sizes_all';

    private const BOX_SIZES_TTL = 3600; // 1 hour

    private const ACTIVE_CARRIER_SERVICES_KEY = 'carrier_services_active';

    private const CARRIER_SERVICES_TTL = 3600; // 1 hour

    /**
     * Get all box sizes with caching.
     *
     * @return Collection<int, BoxSize>
     */
    public function getBoxSizes(): Collection
    {
        $rows = Cache::remember(self::BOX_SIZES_KEY, self::BOX_SIZES_TTL, function () {
            return BoxSize::orderBy('label')->get()->map->getAttributes()->toArray();
        });

        return collect($rows)->map(fn (array $attrs) => (new BoxSize)->newFromBuilder($attrs));
    }

    /**
     * Get box sizes formatted for Pack page lookup (keyed by code).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getBoxSizesForPacking(): array
    {
        return $this->getBoxSizes()
            ->map(fn (BoxSize $box) => [
                'id' => $box->id,
                'code' => $box->code,
                'height' => (string) $box->height,
                'width' => (string) $box->width,
                'length' => (string) $box->length,
            ])
            ->keyBy('code')
            ->toArray();
    }

    /**
     * Get active carrier services with caching.
     *
     * @return Collection<int, CarrierService>
     */
    public function getActiveCarrierServices(): Collection
    {
        $rows = Cache::remember(self::ACTIVE_CARRIER_SERVICES_KEY, self::CARRIER_SERVICES_TTL, function () {
            return CarrierService::active()
                ->withActiveCarrier()
                ->with('carrier')
                ->orderBy('name')
                ->get()
                ->map(function (CarrierService $service) {
                    $attrs = $service->getAttributes();
                    $attrs['_carrier'] = $service->carrier->getAttributes();

                    return $attrs;
                })
                ->toArray();
        });

        return collect($rows)->map(function (array $attrs) {
            $carrierAttrs = $attrs['_carrier'];
            unset($attrs['_carrier']);

            $service = (new CarrierService)->newFromBuilder($attrs);
            $service->setRelation('carrier', (new Carrier)->newFromBuilder($carrierAttrs));

            return $service;
        });
    }

    /**
     * Get active carrier services grouped by carrier name.
     *
     * @return Collection<string, Collection<int, CarrierService>>
     */
    public function getActiveCarrierServicesByCarrier(): Collection
    {
        return $this->getActiveCarrierServices()
            ->groupBy(fn (CarrierService $service) => $service->carrier->name);
    }

    /**
     * Clear box sizes cache.
     */
    public function clearBoxSizesCache(): void
    {
        Cache::forget(self::BOX_SIZES_KEY);
    }

    /**
     * Clear carrier services cache.
     */
    public function clearCarrierServicesCache(): void
    {
        Cache::forget(self::ACTIVE_CARRIER_SERVICES_KEY);
    }

    /**
     * Clear all configuration caches.
     */
    public function clearAll(): void
    {
        $this->clearBoxSizesCache();
        $this->clearCarrierServicesCache();
    }
}
