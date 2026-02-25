<?php

use App\Enums\ShippingRuleAction;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Services\RuleEvaluator;

it('returns empty result when no rules exist', function (): void {
    $shipment = Shipment::factory()->create();

    $result = RuleEvaluator::evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeFalse()
        ->and($result->shouldFilterRates())->toBeFalse()
        ->and($result->excludedServiceCodes)->toBe([]);
});

it('returns pre-selected rate for UseService rule', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $method = ShippingMethod::factory()->create();
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    ShippingRule::factory()->create([
        'shipping_method_id' => $method->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
    ]);

    $result = RuleEvaluator::evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue()
        ->and($result->preSelectedRate->carrier)->toBe('USPS')
        ->and($result->preSelectedRate->serviceCode)->toBe('PRIORITY_MAIL')
        ->and($result->preSelectedRate->price)->toBe(0.0);
});

it('returns excluded service codes for ExcludeService rule', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'FedEx']);
    $service = CarrierService::factory()->fedexGround()->create(['carrier_id' => $carrier->id]);
    $method = ShippingMethod::factory()->create();
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    ShippingRule::factory()->excludeService()->create([
        'shipping_method_id' => $method->id,
        'carrier_service_id' => $service->id,
    ]);

    $result = RuleEvaluator::evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeFalse()
        ->and($result->shouldFilterRates())->toBeTrue()
        ->and($result->excludedServiceCodes)->toBe(['FEDEX_GROUND']);
});

it('evaluates rules in priority order', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'UPS']);
    $groundService = CarrierService::factory()->upsGround()->create(['carrier_id' => $carrier->id]);
    $nextDayService = CarrierService::factory()->upsNextDay()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create();

    // Lower priority (evaluated first) — UseService
    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $groundService->id,
        'priority' => 0,
    ]);

    // Higher priority number (evaluated second) — UseService
    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $nextDayService->id,
        'priority' => 10,
    ]);

    $result = RuleEvaluator::evaluate($shipment);

    // First UseService match wins
    expect($result->preSelectedRate->serviceCode)->toBe('03');
});

it('skips disabled rules', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create();

    ShippingRule::factory()->disabled()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
    ]);

    $result = RuleEvaluator::evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeFalse();
});

it('scopes rules to specific shipping method', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $methodA = ShippingMethod::factory()->create();
    $methodB = ShippingMethod::factory()->create();
    $shipment = Shipment::factory()->create(['shipping_method_id' => $methodA->id]);

    // Rule only applies to method B
    ShippingRule::factory()->create([
        'shipping_method_id' => $methodB->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
    ]);

    $result = RuleEvaluator::evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeFalse();
});

it('matches global rules with null shipping_method_id', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create();

    ShippingRule::factory()->create([
        'shipping_method_id' => null,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
    ]);

    $result = RuleEvaluator::evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue()
        ->and($result->preSelectedRate->serviceCode)->toBe('PRIORITY_MAIL');
});

it('collects exclude codes before UseService stops evaluation', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'FedEx']);
    $excludeService = CarrierService::factory()->fedexGround()->create(['carrier_id' => $carrier->id]);
    $useService = CarrierService::factory()->fedexExpress()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create();

    // Exclude first (lower priority)
    ShippingRule::factory()->excludeService()->create([
        'carrier_service_id' => $excludeService->id,
        'priority' => 0,
    ]);

    // UseService second
    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $useService->id,
        'priority' => 10,
    ]);

    $result = RuleEvaluator::evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue()
        ->and($result->excludedServiceCodes)->toBe(['FEDEX_GROUND']);
});
