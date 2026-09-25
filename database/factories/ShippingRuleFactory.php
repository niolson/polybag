<?php

namespace Database\Factories;

use App\Enums\ShippingRuleAction;
use App\Enums\ShippingRuleSource;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\ShippingRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingRule>
 */
class ShippingRuleFactory extends Factory
{
    protected $model = ShippingRule::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'shipping_method_id' => null,
            'priority' => 0,
            'conditions' => null,
            'action' => ShippingRuleAction::UseService,
            'source' => ShippingRuleSource::Direct,
            'carrier_service_id' => CarrierService::factory(),
            'any_service' => false,
            'carrier_id' => null,
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }

    public function excludeService(): static
    {
        return $this->state(fn () => [
            'action' => ShippingRuleAction::ExcludeService,
            'source' => ShippingRuleSource::Any,
        ]);
    }

    public function source(ShippingRuleSource $source): static
    {
        return $this->state(fn () => ['source' => $source]);
    }

    public function anyService(): static
    {
        return $this->state(fn () => ['carrier_service_id' => null, 'any_service' => true]);
    }

    /**
     * An *Exclude* rule matching every offer this carrier carries.
     */
    public function excludeCarrier(Carrier $carrier, ShippingRuleSource $source = ShippingRuleSource::Any): static
    {
        return $this->excludeService()->anyService()->state(fn () => [
            'source' => $source,
            'carrier_id' => $carrier->id,
        ]);
    }

    public function priority(int $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }
}
