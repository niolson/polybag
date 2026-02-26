<?php

namespace App\Services;

use App\Models\BoxSize;
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
        return Cache::remember(self::BOX_SIZES_KEY, self::BOX_SIZES_TTL, function () {
            return BoxSize::orderBy('label')->get();
        });
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
        return Cache::remember(self::ACTIVE_CARRIER_SERVICES_KEY, self::CARRIER_SERVICES_TTL, function () {
            return CarrierService::active()
                ->withActiveCarrier()
                ->with('carrier')
                ->orderBy('name')
                ->get();
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
