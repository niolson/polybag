<?php

use App\DataTransferObjects\PostageSources\ObservedServiceIdentity;
use App\DataTransferObjects\Shipping\BlindPurchaseOffer;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\AmazonChannelType;
use App\Enums\PostageSourceKind;
use App\Enums\ShippingRuleAction;
use App\Enums\ShippingRuleSource;
use App\Enums\SourceEnvironment;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\Channel;
use App\Models\Client;
use App\Models\Package;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodPostageSource;
use App\Models\ShippingRule;
use App\Models\SourceServiceMapping;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\Carriers\ShopifyAdapter;
use App\Services\RuleEvaluator;

it('returns empty result when no rules exist', function (): void {
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeFalse()
        ->and($result->shouldFilterRates())->toBeFalse()
        ->and($result->exclusions)->toBe([]);
});

it('returns pre-selected rate for UseService rule', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach($service);
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
        ->and($result->excludes(ruleDirectRate($service)))->toBeTrue();
});

it('evaluates rules in priority order', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'UPS']);
    $groundService = CarrierService::factory()->upsGround()->create(['carrier_id' => $carrier->id]);
    $nextDayService = CarrierService::factory()->upsNextDay()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

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
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

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
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

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
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

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
        ->and($result->excludes(ruleDirectRate($excludeService)))->toBeTrue()
        ->and($result->excludes(ruleDirectRate($useService)))->toBeFalse();
});

// --- Condition evaluation tests ---

it('matches rule with weight condition when package weight satisfies operator', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->withoutShippingMethod()->create();
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
    $shipment = Shipment::factory()->withoutShippingMethod()->create();
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
    $shipment = Shipment::factory()->withoutShippingMethod()->create();
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
    $shipment = Shipment::factory()->withoutShippingMethod()->create([
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
    $shipment = Shipment::factory()->withoutShippingMethod()->create([
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
    $shipment = Shipment::factory()->withoutShippingMethod()->create([
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
    $shipment = Shipment::factory()->international()->withoutShippingMethod()->create();

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
    $shipment = Shipment::factory()->withoutShippingMethod()->create([
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
    $shipment = Shipment::factory()->withoutShippingMethod()->create([
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
    $shipment = Shipment::factory()->withoutShippingMethod()->create(['value' => 150.00]);

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
    $shipment = Shipment::factory()->withoutShippingMethod()->create();
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
    $shipment = Shipment::factory()->withoutShippingMethod()->create(['channel_id' => $channel->id]);

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
    $shipment = Shipment::factory()->withoutShippingMethod()->create(['channel_id' => $channel->id]);

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
    $shipment = Shipment::factory()->residential()->withoutShippingMethod()->create();

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

it('uses the conservative residential fallback when classification is unknown', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->withoutShippingMethod()->create([
        'residential' => null,
        'validated_residential' => null,
    ]);

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
    $shipment = Shipment::factory()->commercial()->withoutShippingMethod()->create();

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
    $shipment = Shipment::factory()->withoutShippingMethod()->create([
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
    $shipment = Shipment::factory()->withoutShippingMethod()->create([
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
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

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
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

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
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

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
    $shipment = Shipment::factory()->withoutShippingMethod()->create([
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
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

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

it('does not apply a client-specific rule to a shipment belonging to a different client', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);

    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();

    $shipment = Shipment::factory()->withoutShippingMethod()->create(['client_id' => $clientB->id]);

    ShippingRule::factory()->create([
        'client_id' => $clientA->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeFalse();
});

it('applies a global rule (null client_id) to shipments from any client', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);

    $client = Client::factory()->create();
    $shipment = Shipment::factory()->withoutShippingMethod()->create(['client_id' => $client->id]);

    ShippingRule::factory()->create([
        'client_id' => null,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('applies a client-specific rule only to shipments for that client', function (): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);

    $client = Client::factory()->create();
    $shipment = Shipment::factory()->withoutShippingMethod()->create(['client_id' => $client->id]);

    ShippingRule::factory()->create([
        'client_id' => $client->id,
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue();
});

it('matches an Amazon program condition only on an order enrolled in that program', function (string $program, ?array $metadata, bool $matches): void {
    $carrier = Carrier::factory()->create(['name' => 'USPS']);
    $service = CarrierService::factory()->uspsPriority()->create(['carrier_id' => $carrier->id]);
    $shipment = Shipment::factory()->withoutShippingMethod()->create(['metadata' => $metadata]);

    ShippingRule::factory()->create([
        'action' => ShippingRuleAction::UseService,
        'carrier_service_id' => $service->id,
        'conditions' => [
            ['type' => 'amazon_program', 'data' => ['program' => $program]],
        ],
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBe($matches);
})->with([
    'prime rule, prime order' => ['prime', ['amazon_order_id' => '111', 'amazon_programs' => ['PRIME']], true],
    'prime rule, premium order' => ['prime', ['amazon_order_id' => '111', 'amazon_programs' => ['PREMIUM']], false],
    'premium rule, premium order' => ['premium', ['amazon_order_id' => '111', 'amazon_programs' => ['AMAZON_BUSINESS', 'PREMIUM']], true],
    'premium rule, prime order' => ['premium', ['amazon_order_id' => '111', 'amazon_programs' => ['PRIME']], false],
    'prime rule, ordinary Amazon order' => ['prime', ['amazon_order_id' => '111', 'amazon_programs' => []], false],
    'prime rule, Ship Plus order' => ['prime', ['amazon_order_id' => '111', 'amazon_programs' => ['FBM_SHIP_PLUS']], false],
    'prime rule, imported before programs' => ['prime', ['amazon_order_id' => '111'], false],
    'prime rule, Shopify order' => ['prime', ['shopify_order_id' => 'gid://shopify/Order/1'], false],
    'premium rule, order with no metadata' => ['premium', null, false],
]);

// --- A rule names a source and a service (`carrier-catalog-reset/07`) ---

/**
 * A rate quoted on a carrier account for a catalog service.
 */
function ruleDirectRate(CarrierService $service, float $price = 5.0): RateResponse
{
    return new RateResponse(
        carrier: $service->carrier->name,
        serviceCode: $service->service_code,
        serviceName: $service->name,
        price: $price,
        carrierServiceId: $service->id,
        carrierId: $service->carrier_id,
    );
}

/**
 * An Amazon Buy Shipping offer, mapped to a catalog service or not.
 */
function ruleAmazonOffer(?CarrierService $service, float $price = 4.0, ?int $carrierId = null, string $externalServiceId = 'SOME_SERVICE'): RateResponse
{
    return new RateResponse(
        carrier: $service?->carrier->name ?? 'Amazon',
        serviceCode: $service->service_code ?? $externalServiceId,
        serviceName: $service->name ?? $externalServiceId,
        price: $price,
        observedService: new ObservedServiceIdentity(
            source: 'amazon',
            environment: SourceEnvironment::Production,
            channelType: AmazonChannelType::Amazon,
            externalCarrierId: 'EXTERNAL',
            externalServiceId: $externalServiceId,
        ),
        carrierServiceId: $service?->id,
        carrierId: $carrierId ?? $service?->carrier_id,
    );
}

function ruleAmazonHookRow(): CarrierService
{
    $amazon = Carrier::firstOrCreate(['name' => AmazonBuyShippingAdapter::SOURCE_NAME]);

    return CarrierService::firstOrCreate([
        'carrier_id' => $amazon->id,
        'service_code' => AmazonBuyShippingAdapter::CATALOG_SERVICE_CODE,
    ], ['name' => 'Amazon Buy Shipping']);
}

/**
 * A catalog service Shopify sells, under the Shopify mapping `$shopifyCode`.
 */
function ruleShopifyRow(string $shopifyCode = 'usps:GroundAdvantage'): CarrierService
{
    [$externalCarrierId, $externalServiceId] = explode(':', $shopifyCode, 2);
    $service = CarrierService::factory()->create([
        'carrier_id' => Carrier::firstOrCreate(['name' => $externalCarrierId === 'usps' ? Carrier::USPS : Carrier::UPS])->id,
        'service_code' => strtoupper($externalServiceId),
        'name' => $externalServiceId,
    ]);

    SourceServiceMapping::map(PostageSourceKind::Shopify, $externalCarrierId, $externalServiceId, $service->id);

    return $service;
}

/**
 * Let Shopify sell for the method, and choose for itself when `$auto`.
 */
function ruleAllowShopify(ShippingMethod $method, bool $auto = false): ShippingMethod
{
    $row = ShippingMethodPostageSource::factory()->shopify()->for($method);

    ($auto ? $row->any() : $row)->create();

    return $method->fresh();
}

function ruleUpsGround(): CarrierService
{
    $ups = Carrier::firstOrCreate(['name' => 'UPS']);

    return CarrierService::factory()->upsGround()->create(['carrier_id' => $ups->id]);
}

/**
 * @param  array<int, CarrierService>  $services
 */
function ruleMethodListing(array $services): ShippingMethod
{
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach(collect($services)->pluck('id'));

    return $method;
}

it('pre-selects the direct rate for Direct, UPS Ground, never Amazon\'s', function (): void {
    $ground = ruleUpsGround();
    $method = ruleMethodListing([$ground, ruleAmazonHookRow()]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    ShippingRule::factory()->source(ShippingRuleSource::Direct)->create([
        'shipping_method_id' => $method->id,
        'carrier_service_id' => $ground->id,
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue()
        ->and($result->preSelectedRate->carrierServiceId)->toBe($ground->id)
        ->and($result->isPreSelected(ruleDirectRate($ground)))->toBeTrue()
        ->and($result->isPreSelected(ruleAmazonOffer($ground)))->toBeFalse();
});

it('rate-shops one service across direct and Amazon for Any priced source', function (): void {
    $ground = ruleUpsGround();
    $method = ruleMethodListing([$ground, ruleAmazonHookRow()]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    ShippingRule::factory()->source(ShippingRuleSource::AnyPriced)->create([
        'shipping_method_id' => $method->id,
        'carrier_service_id' => $ground->id,
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedScope())->toBeTrue()
        ->and($result->preSelectedScope->strict)->toBeFalse()
        ->and($result->preSelectedScope->kinds)->toBe([PostageSourceKind::Direct, PostageSourceKind::Amazon])
        ->and($result->isPreSelected(ruleDirectRate($ground)))->toBeTrue()
        ->and($result->isPreSelected(ruleAmazonOffer($ground)))->toBeTrue()
        ->and($result->isPreSelected(ruleDirectRate(ruleUpsGround())))->toBeFalse();
});

it('leaves Amazon out of Any priced source when the method does not allow it', function (): void {
    $ground = ruleUpsGround();
    $method = ruleMethodListing([$ground]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    ShippingRule::factory()->source(ShippingRuleSource::AnyPriced)->create([
        'shipping_method_id' => $method->id,
        'carrier_service_id' => $ground->id,
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->preSelectedScope->kinds)->toBe([PostageSourceKind::Direct])
        ->and($result->isPreSelected(ruleAmazonOffer($ground)))->toBeFalse();
});

it('selects strictly among Amazon offers for Amazon Buy Shipping, any', function (): void {
    $method = ruleMethodListing([ruleAmazonHookRow()]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    ShippingRule::factory()->source(ShippingRuleSource::Amazon)->anyService()->create([
        'shipping_method_id' => $method->id,
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedScope())->toBeTrue()
        ->and($result->preSelectedScope->strict)->toBeTrue()
        ->and($result->isPreSelected(ruleAmazonOffer(null)))->toBeTrue()
        ->and($result->isPreSelected(ruleDirectRate(ruleUpsGround())))->toBeFalse();
});

it('pre-selects the Shopify blind purchase a Shopify rule names, by the service\'s mapped code', function (): void {
    $row = ruleShopifyRow();
    $method = ruleAllowShopify(ruleMethodListing([$row]));
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    ShippingRule::factory()->source(ShippingRuleSource::Shopify)->create([
        'shipping_method_id' => $method->id,
        'carrier_service_id' => $row->id,
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->preSelectedBlindPurchaseId)->toBe(BlindPurchaseOffer::identifier(ShopifyAdapter::CARRIER_NAME, 'usps:GroundAdvantage'));
});

it('pre-selects Shopify\'s own choice when a Shopify rule leaves the service open and the method allows auto', function (bool $auto): void {
    $method = ruleAllowShopify(ruleMethodListing([ruleUpsGround()]), auto: $auto);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    ShippingRule::factory()->source(ShippingRuleSource::Shopify)->anyService()->create([
        'shipping_method_id' => $method->id,
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->preSelectedBlindPurchaseId)->toBe($auto ? 'Shopify:auto' : null);
})->with([
    'auto allowed' => [true],
    'auto not allowed' => [false],
]);

it('skips a Shopify rule naming a service Shopify has no mapping for', function (): void {
    $ground = ruleUpsGround();
    $method = ruleAllowShopify(ruleMethodListing([$ground]));
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    ShippingRule::factory()->source(ShippingRuleSource::Shopify)->create([
        'shipping_method_id' => $method->id,
        'carrier_service_id' => $ground->id,
    ]);

    expect(app(RuleEvaluator::class)->evaluate($shipment)->hasPreSelectedBlindPurchase())->toBeFalse();
});

it('skips a Use rule naming a source the method does not allow', function (ShippingRuleSource $source, bool $anyService): void {
    $ground = ruleUpsGround();
    $row = ruleShopifyRow();
    $method = ruleMethodListing([$ground]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    ShippingRule::factory()->source($source)->create([
        'shipping_method_id' => $method->id,
        'carrier_service_id' => $anyService ? null : ($source === ShippingRuleSource::Shopify ? $row->id : $ground->id),
        'any_service' => $anyService,
    ]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeFalse()
        ->and($result->hasPreSelectedScope())->toBeFalse()
        ->and($result->hasPreSelectedBlindPurchase())->toBeFalse();
})->with([
    'Amazon, any' => [ShippingRuleSource::Amazon, true],
    'Amazon, a service' => [ShippingRuleSource::Amazon, false],
    'Shopify, a service' => [ShippingRuleSource::Shopify, false],
    'Shopify, auto' => [ShippingRuleSource::Shopify, true],
]);

it('skips a global Use rule naming a service the shipment\'s method does not list, and applies the next', function (): void {
    $ground = ruleUpsGround();
    $unlisted = CarrierService::factory()->upsNextDay()->create(['carrier_id' => $ground->carrier_id]);
    $method = ruleMethodListing([$ground]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    ShippingRule::factory()->create(['carrier_service_id' => $unlisted->id, 'priority' => 0]);
    ShippingRule::factory()->create(['carrier_service_id' => $ground->id, 'priority' => 10]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->preSelectedRate->carrierServiceId)->toBe($ground->id);
});

it('allows a shipment with no method every direct service and nothing else', function (): void {
    $ground = ruleUpsGround();
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

    ShippingRule::factory()->source(ShippingRuleSource::Amazon)->anyService()->create(['priority' => 0]);
    ShippingRule::factory()->source(ShippingRuleSource::Shopify)->create(['carrier_service_id' => ruleShopifyRow()->id, 'priority' => 1]);
    ShippingRule::factory()->create(['carrier_service_id' => $ground->id, 'priority' => 2]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->hasPreSelectedRate())->toBeTrue()
        ->and($result->preSelectedRate->carrierServiceId)->toBe($ground->id);
});

it('never lets a direct Use rule name a channel\'s catalog row', function (): void {
    $method = ruleMethodListing([ruleAmazonHookRow()]);
    $shipment = Shipment::factory()->create(['shipping_method_id' => $method->id]);

    ShippingRule::factory()->create([
        'shipping_method_id' => $method->id,
        'carrier_service_id' => ruleAmazonHookRow()->id,
    ]);

    expect(app(RuleEvaluator::class)->evaluate($shipment)->hasPreSelectedRate())->toBeFalse();
});

it('excludes a service from every source for Exclude, any source', function (): void {
    $ground = ruleUpsGround();
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

    ShippingRule::factory()->excludeService()->create(['carrier_service_id' => $ground->id]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->excludes(ruleDirectRate($ground)))->toBeTrue()
        ->and($result->excludes(ruleAmazonOffer($ground)))->toBeTrue()
        ->and($result->excludes(ruleDirectRate(ruleUpsGround())))->toBeFalse();
});

it('excludes only the named source\'s offers of a service', function (): void {
    $ground = ruleUpsGround();
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

    ShippingRule::factory()->excludeService()->source(ShippingRuleSource::Amazon)->create(['carrier_service_id' => $ground->id]);

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->excludes(ruleAmazonOffer($ground)))->toBeTrue()
        ->and($result->excludes(ruleDirectRate($ground)))->toBeFalse();
});

it('excludes every offer a carrier carries, mapped or not, for Exclude, Amazon Buy Shipping, OnTrac', function (): void {
    $onTrac = Carrier::factory()->create(['name' => 'OnTrac']);
    $onTracGround = CarrierService::factory()->create(['carrier_id' => $onTrac->id, 'service_code' => 'ONTRAC_GROUND']);
    $shipment = Shipment::factory()->withoutShippingMethod()->create();

    ShippingRule::factory()->excludeCarrier($onTrac, ShippingRuleSource::Amazon)->create();

    $result = app(RuleEvaluator::class)->evaluate($shipment);

    expect($result->excludes(ruleAmazonOffer(null, carrierId: $onTrac->id, externalServiceId: 'ONTRAC_NEXT_DAY')))->toBeTrue()
        ->and($result->excludes(ruleAmazonOffer($onTracGround)))->toBeTrue()
        ->and($result->excludes(ruleAmazonOffer(ruleUpsGround())))->toBeFalse()
        ->and($result->excludes(ruleDirectRate($onTracGround)))->toBeFalse();
});

it('excludes Shopify blind purchases by source, by carrier or by the service a rule names', function (): void {
    $row = ruleShopifyRow();
    $other = ruleShopifyRow('ups_shipping:03');
    $shipment = Shipment::factory()->withoutShippingMethod()->create();
    $offer = fn (CarrierService $service): BlindPurchaseOffer => new BlindPurchaseOffer(
        source: ShopifyAdapter::CARRIER_NAME,
        sourceLabel: ShopifyAdapter::SOURCE_LABEL,
        serviceCode: (string) ShopifyAdapter::serviceCodeFor($service->id),
        selectionLabel: $service->name,
        carrierServiceId: $service->id,
        carrierId: $service->carrier_id,
    );
    $auto = new BlindPurchaseOffer(
        source: ShopifyAdapter::CARRIER_NAME,
        sourceLabel: ShopifyAdapter::SOURCE_LABEL,
        serviceCode: ShopifyAdapter::AUTO_SERVICE_CODE,
        selectionLabel: ShopifyAdapter::AUTO_SELECTION_LABEL,
    );

    ShippingRule::factory()->excludeService()->create(['carrier_service_id' => $row->id]);
    $byService = app(RuleEvaluator::class)->evaluate($shipment);

    ShippingRule::query()->delete();
    ShippingRule::factory()->excludeService()->anyService()->create(['carrier_id' => $other->carrier_id]);
    $byCarrier = app(RuleEvaluator::class)->evaluate($shipment);

    ShippingRule::query()->delete();
    ShippingRule::factory()->excludeService()->source(ShippingRuleSource::Shopify)->anyService()->create();
    $bySource = app(RuleEvaluator::class)->evaluate($shipment);

    expect($byService->excludesBlindOffer($offer($row)))->toBeTrue()
        ->and($byService->excludesBlindOffer($offer($other)))->toBeFalse()
        // Shopify's own choice has no carrier or service before it is bought.
        ->and($byService->excludesBlindOffer($auto))->toBeFalse()
        ->and($byCarrier->excludesBlindOffer($offer($other)))->toBeTrue()
        ->and($byCarrier->excludesBlindOffer($offer($row)))->toBeFalse()
        ->and($byCarrier->excludesBlindOffer($auto))->toBeFalse()
        ->and($bySource->excludesBlindOffer($offer($other)))->toBeTrue()
        ->and($bySource->excludesBlindOffer($auto))->toBeTrue()
        ->and($bySource->excludes(ruleDirectRate(ruleUpsGround())))->toBeFalse();
});
