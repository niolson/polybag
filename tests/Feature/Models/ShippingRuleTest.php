<?php

use App\Enums\ShippingRuleAction;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;

it('creates shipping rule via factory', function (): void {
    $rule = ShippingRule::factory()->create();

    expect($rule->exists)->toBeTrue()
        ->and($rule->action)->toBe(ShippingRuleAction::UseService)
        ->and($rule->enabled)->toBeTrue();
});

it('active scope filters disabled rules and orders by priority', function (): void {
    $carrier = Carrier::factory()->create();
    $service = CarrierService::factory()->create(['carrier_id' => $carrier->id]);

    ShippingRule::factory()->create([
        'name' => 'Low Priority',
        'carrier_service_id' => $service->id,
        'priority' => 10,
        'enabled' => true,
    ]);

    ShippingRule::factory()->create([
        'name' => 'Disabled',
        'carrier_service_id' => $service->id,
        'priority' => 0,
        'enabled' => false,
    ]);

    ShippingRule::factory()->create([
        'name' => 'High Priority',
        'carrier_service_id' => $service->id,
        'priority' => 0,
        'enabled' => true,
    ]);

    $active = ShippingRule::active()->get();

    expect($active)->toHaveCount(2)
        ->and($active->first()->name)->toBe('High Priority')
        ->and($active->last()->name)->toBe('Low Priority');
});

it('belongs to shipping method', function (): void {
    $method = ShippingMethod::factory()->create();
    $rule = ShippingRule::factory()->create(['shipping_method_id' => $method->id]);

    expect($rule->shippingMethod->id)->toBe($method->id);
});

it('belongs to carrier service', function (): void {
    $carrier = Carrier::factory()->create();
    $service = CarrierService::factory()->create(['carrier_id' => $carrier->id]);
    $rule = ShippingRule::factory()->create(['carrier_service_id' => $service->id]);

    expect($rule->carrierService->id)->toBe($service->id);
});

it('allows null shipping method for global rules', function (): void {
    $rule = ShippingRule::factory()->create(['shipping_method_id' => null]);

    expect($rule->shipping_method_id)->toBeNull()
        ->and($rule->shippingMethod)->toBeNull();
});

it('casts conditions to array', function (): void {
    $rule = ShippingRule::factory()->create(['conditions' => ['min_weight' => 5]]);

    expect($rule->conditions)->toBe(['min_weight' => 5]);
});
