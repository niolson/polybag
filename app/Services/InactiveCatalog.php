<?php

namespace App\Services;

use App\DataTransferObjects\Shipping\RateResponse;
use App\Models\Carrier;
use App\Models\CarrierService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The catalog services and carriers a set of rates names that somebody
 * deactivated — `carrier-catalog-reset/13`.
 *
 * Deactivating one means "do not buy this", by a packer or by automation,
 * whatever the shipping method allows. Rate shopping already asks a direct
 * source only for an active service of an active carrier. This holds the
 * offers a source discovers — Amazon's, mapped to a catalog service or naming
 * a catalog carrier — to the same, so the Ship page shows them unselectable,
 * automation refuses them, and the purchase refuses them again.
 *
 * A rate naming neither, such as an Amazon offer from a carrier the catalog
 * does not hold, names nothing that can be deactivated.
 */
final class InactiveCatalog
{
    /**
     * @param  array<int, string>  $serviceReasons  keyed by carrier service id
     * @param  array<int, string>  $carrierReasons  keyed by carrier id
     */
    private function __construct(
        private readonly array $serviceReasons,
        private readonly array $carrierReasons,
    ) {}

    /**
     * Read once for every rate given. Asks the database nothing when no rate
     * names a catalog service or carrier.
     *
     * @param  Collection<int, RateResponse>  $rates
     */
    public static function among(Collection $rates): self
    {
        $serviceIds = $rates->pluck('carrierServiceId')->filter()->unique()->values();
        $carrierIds = $rates->pluck('carrierId')->filter()->unique()->values();

        $serviceReasons = $serviceIds->isEmpty() ? [] : CarrierService::query()
            ->with('carrier')
            ->whereKey($serviceIds)
            ->where(fn (Builder $query) => $query
                ->where('active', false)
                ->orWhereHas('carrier', fn (Builder $carrier) => $carrier->where('active', false)))
            ->get()
            ->mapWithKeys(fn (CarrierService $service): array => [$service->id => $service->active
                ? self::carrierReason($service->carrier)
                : "{$service->name} is inactive."])
            ->all();

        $carrierReasons = $carrierIds->isEmpty() ? [] : Carrier::query()
            ->whereKey($carrierIds)
            ->where('active', false)
            ->get()
            ->mapWithKeys(fn (Carrier $carrier): array => [$carrier->id => self::carrierReason($carrier)])
            ->all();

        return new self($serviceReasons, $carrierReasons);
    }

    /**
     * Why this rate may not be bought, or null when nothing it names is
     * inactive.
     */
    public function reasonFor(RateResponse $rate): ?string
    {
        return ($rate->carrierServiceId !== null ? ($this->serviceReasons[$rate->carrierServiceId] ?? null) : null)
            ?? ($rate->carrierId !== null ? ($this->carrierReasons[$rate->carrierId] ?? null) : null);
    }

    public function includes(RateResponse $rate): bool
    {
        return $this->reasonFor($rate) !== null;
    }

    private static function carrierReason(?Carrier $carrier): string
    {
        return ($carrier?->label() ?? 'The carrier').' is inactive.';
    }
}
