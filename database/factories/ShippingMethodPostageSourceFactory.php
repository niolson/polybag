<?php

namespace Database\Factories;

use App\Enums\PostageSourceKind;
use App\Enums\UnlistedServices;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodPostageSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingMethodPostageSource>
 */
class ShippingMethodPostageSourceFactory extends Factory
{
    protected $model = ShippingMethodPostageSource::class;

    /**
     * A `shopify` row by default: every method is created with its `direct` row.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shipping_method_id' => ShippingMethod::factory(),
            'source_kind' => PostageSourceKind::Shopify,
            'unlisted_services' => UnlistedServices::None,
        ];
    }

    public function direct(): static
    {
        return $this->state(fn (): array => ['source_kind' => PostageSourceKind::Direct]);
    }

    public function shopify(): static
    {
        return $this->state(fn (): array => ['source_kind' => PostageSourceKind::Shopify]);
    }

    public function amazon(): static
    {
        return $this->state(fn (): array => ['source_kind' => PostageSourceKind::Amazon]);
    }

    /**
     * May sell beyond the method's services: Shopify's `auto`. Amazon Buy
     * Shipping takes it from `carrier-catalog-reset/13`.
     */
    public function any(): static
    {
        return $this->state(fn (): array => ['unlisted_services' => UnlistedServices::Any]);
    }
}
