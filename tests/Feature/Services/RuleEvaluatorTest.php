<?php

use App\Enums\ShippingRuleAction;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Channel;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Services\RuleEvaluator;

it('returns empty result when no rules exist', function (): void {
    $shipment = Shipment::factory()->create();

    $result = app(RuleEvaluator::class)->evaluate($shipment);

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

    $result = app(RuleEvaluator::class)->evaluate($shipment);

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

    $result = app(RuleEvaluator::class)->evaluate($shipment);

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

    $result = app(RuleEvaluator::class)->evaluate($shipment);

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

    $result = app(RuleEvaluator::class)->evaluate($shipment);

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

    $result = app(RuleEvaluator::class)->evaluate($shipment);

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

    $result = app(RuleEvaluator::class)->evaluate($shipment);

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

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue()
        ->and($result->excludedServiceCodes)->toBe(['FEDEX_GROUND']);
});

// --- Condition evaluation tests ---

it('matches rule with weight condition when package weight satisfies operator', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->create(['shipment_id' => $shipment->id, 'weight' => 20]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'weight', 'data' => ['operator' => '>=', 'value' => 16]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment, $package);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('skips rule with weight condition when weight does not match', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->create(['shipment_id' => $shipment->id, 'weight' => 10]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'weight', 'data' => ['operator' => '>=', 'value' => 16]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment, $package);

    expect($result->hasPreSelectedRate())->toBeFalse();
});

it('matches weight between condition', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create();
    $package = Package::factory()->create(['shipment_id' => $shipment->id, 'weight' => 12]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'weight', 'data' => ['operator' => 'between', 'value' => 8, 'max_value' => 16]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment, $package);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('matches destination_zone condition for continental US', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create([
        'state_or_province' => 'NY',
        'country' => 'US',
    ]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'destination_zone', 'data' => ['zone' => 'continental_us']],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('skips destination_zone condition for non-continental shipment when rule requires continental', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create([
        'state_or_province' => 'HI',
        'country' => 'US',
    ]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'destination_zone', 'data' => ['zone' => 'continental_us']],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeFalse();
});

it('matches destination_zone condition for non-continental US (AK)', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create([
        'state_or_province' => 'AK',
        'country' => 'US',
    ]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'destination_zone', 'data' => ['zone' => 'non_continental_us']],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('matches destination_zone condition for international', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->international()->create();

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'destination_zone', 'data' => ['zone' => 'international']],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('matches destination_state in condition', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create([
        'state_or_province' => 'CA',
        'country' => 'US',
    ]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'destination_state', 'data' => ['operator' => 'in', 'states' => ['CA', 'NY', 'TX']]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('skips destination_state not_in condition when state is excluded', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create([
        'state_or_province' => 'CA',
        'country' => 'US',
    ]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'destination_state', 'data' => ['operator' => 'not_in', 'states' => ['CA', 'NY']]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeFalse();
});

it('matches order_value condition', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create(['value' => 150.00]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'order_value', 'data' => ['operator' => '>=', 'value' => 100]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('matches item_count condition', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create();
    ShipmentItem::factory()->count(3)->create([
        'shipment_id' => $shipment->id,
        'quantity' => 2,
    ]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'item_count', 'data' => ['operator' => '>=', 'value' => 5]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    // 3 items x 2 qty = 6, >= 5
    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('matches channel condition', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $channel = Channel::factory()->create();
    $shipment = Shipment::factory()->create(['channel_id' => $channel->id]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'channel', 'data' => ['operator' => 'is', 'channel_id' => $channel->id]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('skips channel is_not condition when channel matches', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $channel = Channel::factory()->create();
    $shipment = Shipment::factory()->create(['channel_id' => $channel->id]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'channel', 'data' => ['operator' => 'is_not', 'channel_id' => $channel->id]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeFalse();
});

it('matches residential condition', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->residential()->create();

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'residential', 'data' => ['is_residential' => true]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('skips residential condition when shipment is commercial', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->commercial()->create();

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'residential', 'data' => ['is_residential' => true]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeFalse();
});

it('requires all conditions to match (AND logic)', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create([
        'state_or_province' => 'NY',
        'country' => 'US',
        'value' => 200.00,
    ]);
    $package = Package::factory()->create(['shipment_id' => $shipment->id, 'weight' => 20]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'weight', 'data' => ['operator' => '>=', 'value' => 16]],
            ['type' => 'destination_zone', 'data' => ['zone' => 'continental_us']],
            ['type' => 'order_value', 'data' => ['operator' => '>=', 'value' => 100]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment, $package);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('skips rule when one of multiple conditions fails', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create([
        'state_or_province' => 'HI', // Non-continental
        'country' => 'US',
        'value' => 200.00,
    ]);
    $package = Package::factory()->create(['shipment_id' => $shipment->id, 'weight' => 20]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'weight', 'data' => ['operator' => '>=', 'value' => 16]],
            ['type' => 'destination_zone', 'data' => ['zone' => 'continental_us']], // Fails
            ['type' => 'order_value', 'data' => ['operator' => '>=', 'value' => 100]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment, $package);

    expect($result->hasPreSelectedRate())->toBeFalse();
});

it('matches rule with null conditions (backward compatible)', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create();

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => null,
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('matches rule with empty conditions array (backward compatible)', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create();

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('uses calculated weight from items when no package provided', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create();

    $product = Product::factory()->create(['weight' => 5.0]);
    ShipmentItem::factory()->create([
        'shipment_id' => $shipment->id,
        'product_id' => $product->id,
        'quantity' => 4,
    ]);

    // Total calculated weight = 4 * 5 = 20 lbs
    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'weight', 'data' => ['operator' => '>=', 'value' => 16]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('uses validated address fields when available for destination conditions', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create([
        'state_or_province' => 'XX', // Invalid original
        'country' => 'US',
        'validated_state_or_province' => 'NY', // Corrected by validation
    ]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'destination_zone', 'data' => ['zone' => 'continental_us']],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('passes unknown condition types (forward compatibility)', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->create();

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'future_condition_type', 'data' => ['foo' => 'bar']],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue();
});
