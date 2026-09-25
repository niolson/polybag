<?php

use App\Enums\ShippingRuleAction;
use App\Enums\ShippingRuleSource;
use App\Filament\Resources\Carriers\Pages\EditCarrier;
use App\Filament\Resources\CarrierServiceResource\Pages\EditCarrierService;
use App\Filament\Resources\ShippingMethodResource\Pages\EditShippingMethod;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\ShippingRulesRelationManager;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('creates a rule with an Amazon program condition', function (): void {
    $method = ShippingMethod::factory()->create();
    $service = CarrierService::factory()->uspsPriority()->create();
    $method->carrierServices()->attach($service);

    Livewire::test(ShippingRulesRelationManager::class, [
        'ownerRecord' => $method,
        'pageClass' => EditShippingMethod::class,
    ])
        ->callAction(TestAction::make(CreateAction::class)->table(), [
            'name' => 'Prime goes Priority',
            'action' => ShippingRuleAction::UseService->value,
            'source' => ShippingRuleSource::Direct->value,
            'carrier_service_id' => $service->id,
            'enabled' => true,
            'conditions' => [
                ['type' => 'amazon_program', 'data' => ['program' => 'prime']],
            ],
        ])
        ->assertHasNoFormErrors();

    $rule = ShippingRule::where('shipping_method_id', $method->id)->sole();

    expect(array_values($rule->conditions))->toBe([
        ['type' => 'amazon_program', 'data' => ['program' => 'prime']],
    ]);
});

it('requires a program on an Amazon program condition', function (): void {
    $method = ShippingMethod::factory()->create();
    $service = CarrierService::factory()->uspsPriority()->create();
    $method->carrierServices()->attach($service);

    Livewire::test(ShippingRulesRelationManager::class, [
        'ownerRecord' => $method,
        'pageClass' => EditShippingMethod::class,
    ])
        ->callAction(TestAction::make(CreateAction::class)->table(), [
            'name' => 'Prime goes Priority',
            'action' => ShippingRuleAction::UseService->value,
            'source' => ShippingRuleSource::Direct->value,
            'carrier_service_id' => $service->id,
            'conditions' => [
                ['type' => 'amazon_program', 'data' => ['program' => null]],
            ],
        ])
        ->assertHasFormErrors();

    expect(ShippingRule::where('shipping_method_id', $method->id)->exists())->toBeFalse();
});

it('summarizes an Amazon program condition by the program name', function (): void {
    expect(ShippingRulesRelationManager::summarizeConditions([
        ['type' => 'amazon_program', 'data' => ['program' => 'prime']],
    ]))->toBe('Amazon Prime')
        ->and(ShippingRulesRelationManager::summarizeConditions([
            ['type' => 'amazon_program', 'data' => ['program' => 'premium']],
            ['type' => 'residential', 'data' => ['is_residential' => true]],
        ]))->toBe('Amazon Premium, Residential');
});

function rulesManager(ShippingMethod $method): Testable
{
    return Livewire::test(ShippingRulesRelationManager::class, [
        'ownerRecord' => $method,
        'pageClass' => EditShippingMethod::class,
    ]);
}

it('offers only the method\'s services', function (): void {
    $method = ShippingMethod::factory()->create();
    $listed = CarrierService::factory()->uspsPriority()->create();
    $unlisted = CarrierService::factory()->upsGround()->create();
    $method->carrierServices()->attach($listed);

    rulesManager($method)
        ->mountAction(TestAction::make(CreateAction::class)->table())
        ->fillForm([
            'action' => ShippingRuleAction::UseService->value,
            'source' => ShippingRuleSource::Direct->value,
        ])
        ->assertFormFieldExists('carrier_service_id', function (Select $field) use ($listed, $unlisted): bool {
            $options = $field->getOptions();

            return array_key_exists($listed->id, $options) && ! array_key_exists($unlisted->id, $options);
        });
});

it('offers a Use rule only the sources the method allows', function (): void {
    $method = ShippingMethod::factory()->create();
    $method->carrierServices()->attach(CarrierService::factory()->uspsPriority()->create());

    rulesManager($method)
        ->mountAction(TestAction::make(CreateAction::class)->table())
        ->fillForm(['action' => ShippingRuleAction::UseService->value])
        ->assertFormFieldExists('source', fn (Select $field): bool => array_keys($field->getOptions()) === [
            ShippingRuleSource::Direct->value,
            ShippingRuleSource::AnyPriced->value,
        ]);
});

it('creates an Exclude rule naming a carrier with any service', function (): void {
    $method = ShippingMethod::factory()->create();
    $onTrac = Carrier::factory()->create(['name' => 'OnTrac']);

    rulesManager($method)
        ->callAction(TestAction::make(CreateAction::class)->table(), [
            'name' => 'Never OnTrac',
            'action' => ShippingRuleAction::ExcludeService->value,
            'source' => ShippingRuleSource::Amazon->value,
            'carrier_id' => $onTrac->id,
            'any_service' => true,
            'enabled' => true,
        ])
        ->assertHasNoFormErrors();

    $rule = ShippingRule::where('shipping_method_id', $method->id)->sole();

    expect($rule->source)->toBe(ShippingRuleSource::Amazon)
        ->and($rule->carrier_id)->toBe($onTrac->id)
        ->and($rule->any_service)->toBeTrue()
        ->and($rule->carrier_service_id)->toBeNull();
});

it('requires a carrier on an Exclude rule for any source and any service', function (): void {
    $method = ShippingMethod::factory()->create();

    rulesManager($method)
        ->callAction(TestAction::make(CreateAction::class)->table(), [
            'name' => 'Exclude everything',
            'action' => ShippingRuleAction::ExcludeService->value,
            'source' => ShippingRuleSource::Any->value,
            'any_service' => true,
        ])
        ->assertHasFormErrors(['carrier_id' => 'required']);

    expect(ShippingRule::query()->exists())->toBeFalse();
});

it('refuses to delete a service a rule names, naming the rule', function (): void {
    $method = ShippingMethod::factory()->create(['name' => 'Standard']);
    $service = CarrierService::factory()->create();
    ShippingRule::factory()->create([
        'name' => 'Ground for heavy',
        'shipping_method_id' => $method->id,
        'carrier_service_id' => $service->id,
    ]);

    Livewire::test(EditCarrierService::class, ['record' => $service->id])
        ->callAction(DeleteAction::class)
        ->assertNotified(
            Notification::make()
                ->title('Cannot delete carrier service')
                ->body('The shipping rule “Ground for heavy” on Standard names this service. Change the rule, or deactivate the service instead.')
                ->danger(),
        );

    expect(CarrierService::whereKey($service->id)->exists())->toBeTrue();
});

it('refuses to delete a carrier an Exclude rule names, naming the rule', function (): void {
    $onTrac = Carrier::factory()->create(['name' => 'OnTrac']);
    ShippingRule::factory()->excludeCarrier($onTrac)->create(['name' => 'Never OnTrac']);

    Livewire::test(EditCarrier::class, ['record' => $onTrac->id])
        ->callAction(DeleteAction::class)
        ->assertNotified(
            Notification::make()
                ->title('Cannot delete carrier')
                ->body('The shipping rule “Never OnTrac” (every shipping method) names this carrier or one of its services. Change the rule, or deactivate the carrier instead.')
                ->danger(),
        );

    expect(Carrier::whereKey($onTrac->id)->exists())->toBeTrue();
});

it('refuses to delete a carrier one of whose services a rule names', function (): void {
    $carrier = Carrier::factory()->create();
    $service = CarrierService::factory()->create(['carrier_id' => $carrier->id]);
    ShippingRule::factory()->create(['carrier_service_id' => $service->id]);

    Livewire::test(EditCarrier::class, ['record' => $carrier->id])
        ->callAction(DeleteAction::class)
        ->assertNotified('Cannot delete carrier');

    expect(Carrier::whereKey($carrier->id)->exists())->toBeTrue();
});

it('offers an Exclude rule naming a carrier only that carrier\'s services', function (): void {
    $method = ShippingMethod::factory()->create();
    $onTrac = Carrier::factory()->create(['name' => 'OnTrac']);
    $onTracGround = CarrierService::factory()->create(['carrier_id' => $onTrac->id]);
    $upsGround = CarrierService::factory()->upsGround()->create();
    $method->carrierServices()->attach([$onTracGround->id, $upsGround->id]);

    rulesManager($method)
        ->mountAction(TestAction::make(CreateAction::class)->table())
        ->fillForm([
            'action' => ShippingRuleAction::ExcludeService->value,
            'source' => ShippingRuleSource::Any->value,
            'carrier_id' => $onTrac->id,
        ])
        ->assertFormFieldExists('carrier_service_id', fn (Select $field): bool => array_keys($field->getOptions()) === [$onTracGround->id]);
});

it('refuses to save an Exclude rule naming a carrier and another carrier\'s service', function (): void {
    $method = ShippingMethod::factory()->create();
    $onTrac = Carrier::factory()->create(['name' => 'OnTrac']);
    $upsGround = CarrierService::factory()->upsGround()->create();
    $method->carrierServices()->attach($upsGround);

    rulesManager($method)
        ->callAction(TestAction::make(CreateAction::class)->table(), [
            'name' => 'OnTrac UPS Ground',
            'action' => ShippingRuleAction::ExcludeService->value,
            'source' => ShippingRuleSource::Any->value,
            'carrier_id' => $onTrac->id,
            'carrier_service_id' => $upsGround->id,
        ])
        ->assertHasFormErrors(['carrier_service_id']);

    expect(ShippingRule::query()->exists())->toBeFalse();
});

it('clears a service of another carrier when the carrier changes', function (): void {
    $method = ShippingMethod::factory()->create();
    $onTrac = Carrier::factory()->create(['name' => 'OnTrac']);
    $upsGround = CarrierService::factory()->upsGround()->create();
    $method->carrierServices()->attach($upsGround);

    rulesManager($method)
        ->mountAction(TestAction::make(CreateAction::class)->table())
        ->fillForm([
            'action' => ShippingRuleAction::ExcludeService->value,
            'source' => ShippingRuleSource::Any->value,
            'carrier_service_id' => $upsGround->id,
        ])
        ->fillForm(['carrier_id' => $onTrac->id])
        ->assertFormSet(['carrier_service_id' => null]);
});
