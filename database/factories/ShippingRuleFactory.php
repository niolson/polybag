<?php

namespace Database\Factories;

use App\Enums\ShippingRuleAction;
use App\Models\CarrierService;
use App\Models\ShippingRule;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'carrier_service_id' => CarrierService::factory(),
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }

    public function excludeService(): static
    {
        return $this->state(fn () => ['action' => ShippingRuleAction::ExcludeService]);
    }

    public function priority(int $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }
}
