<?php

use App\Enums\ShippingRuleAction;
use App\Enums\ShippingRuleSource;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use Illuminate\Database\QueryException;

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

it('refuses a rule that names neither one service nor any service, or both', function (array $attributes): void {
    expect(fn () => ShippingRule::factory()->create($attributes))
        ->toThrow(DomainException::class, 'one service or any service');
})->with([
    'neither' => [['carrier_service_id' => null, 'any_service' => false]],
    'both' => [['any_service' => true]],
]);

it('refuses a source its action cannot name', function (ShippingRuleAction $action, ShippingRuleSource $source): void {
    expect(fn () => ShippingRule::factory()->create(['action' => $action, 'source' => $source]))
        ->toThrow(DomainException::class, 'cannot name');
})->with([
    'Use, any source' => [ShippingRuleAction::UseService, ShippingRuleSource::Any],
    'Exclude, any priced source' => [ShippingRuleAction::ExcludeService, ShippingRuleSource::AnyPriced],
]);

it('lets only an Exclude rule name a carrier', function (): void {
    expect(fn () => ShippingRule::factory()->create(['carrier_id' => Carrier::factory()->create()->id]))
        ->toThrow(DomainException::class, 'Only an Exclude rule');
});

it('lets a Use rule leave the service to Amazon Buy Shipping alone', function (): void {
    expect(ShippingRule::factory()->source(ShippingRuleSource::Amazon)->anyService()->create()->any_service)->toBeTrue()
        ->and(fn () => ShippingRule::factory()->anyService()->create())
        ->toThrow(DomainException::class, 'except for Amazon Buy Shipping');
});

it('refuses an Exclude rule that names nothing', function (): void {
    expect(fn () => ShippingRule::factory()->excludeService()->anyService()->create())
        ->toThrow(DomainException::class, 'must name a source, a carrier or a service');
});

it('refuses to delete a service or carrier a rule names', function (): void {
    $carrier = Carrier::factory()->create();
    $service = CarrierService::factory()->create(['carrier_id' => $carrier->id]);
    ShippingRule::factory()->create(['carrier_service_id' => $service->id]);
    $excluded = Carrier::factory()->create();
    ShippingRule::factory()->excludeCarrier($excluded)->create();

    expect(fn () => $service->delete())->toThrow(QueryException::class)
        ->and(fn () => $excluded->delete())->toThrow(QueryException::class);
});

it('refuses an Exclude rule naming a carrier and another carrier\'s service', function (): void {
    $onTrac = Carrier::factory()->create();
    $upsGround = CarrierService::factory()->create();

    expect(fn () => ShippingRule::factory()->excludeCarrier($onTrac)->create([
        'carrier_service_id' => $upsGround->id,
        'any_service' => false,
    ]))->toThrow(DomainException::class, 'another carrier');

    $onTracGround = CarrierService::factory()->create(['carrier_id' => $onTrac->id]);

    expect(ShippingRule::factory()->excludeCarrier($onTrac)->create([
        'carrier_service_id' => $onTracGround->id,
        'any_service' => false,
    ])->exists)->toBeTrue();
});
