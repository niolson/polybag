<?php

namespace App\Services\Shipping;

use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\CarrierPackaging;
use Illuminate\Support\Collection;

/**
 * The one shared rule for carrier packaging — ADR-0005 decision 4.
 *
 * A rate is kept only when its requirement accepts the packaging the Package is
 * in. That is the whole rule, and it enforces the carrier-identity axis only:
 * it knows nothing of `BoxSizeType`, because physical-form filtering (USPS
 * cubic tiers for boxes against soft packs) stays inside the adapters, where
 * both tier tables are honestly `shipperPackaging()` rates.
 *
 * Runs wherever adapter rates are collected: `ShippingRateService::getShippingRates()`,
 * before the quote log, so a rate that was never offered is never logged as
 * one; and inside every `resolvePreSelectedRate()`, where a rule's chosen
 * service reaches automation without passing through rate shopping. An empty
 * result there is the contract's null — the caller rate-shops instead.
 */
final class PackagingFilter
{
    /**
     * @param  Collection<int, RateResponse>  $rates
     * @param  CarrierPackaging|null  $packaging  What the Package is in; null is the packer's own packaging.
     * @return Collection<int, RateResponse>
     */
    public static function keepCompatible(Collection $rates, ?CarrierPackaging $packaging): Collection
    {
        return $rates
            ->filter(fn (RateResponse $rate): bool => $rate->packagingRequirement->accepts($packaging))
            ->values();
    }
}
