<?php

use App\Enums\ShippingRuleAction;
use App\Filament\Resources\ShippingMethodResource\Pages\EditShippingMethod;
use App\Filament\Resources\ShippingMethodResource\RelationManagers\ShippingRulesRelationManager;
use App\Models\CarrierService;
use App\Models\ShippingMethod;
use App\Models\ShippingRule;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('creates a rule with an Amazon program condition', function (): void {
    $method = ShippingMethod::factory()->create();
    $service = CarrierService::factory()->uspsPriority()->create();

    Livewire::test(ShippingRulesRelationManager::class, [
        'ownerRecord' => $method,
        'pageClass' => EditShippingMethod::class,
    ])
        ->callAction(TestAction::make(CreateAction::class)->table(), [
            'name' => 'Prime goes Priority',
            'action' => ShippingRuleAction::UseService->value,
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

    Livewire::test(ShippingRulesRelationManager::class, [
        'ownerRecord' => $method,
        'pageClass' => EditShippingMethod::class,
    ])
        ->callAction(TestAction::make(CreateAction::class)->table(), [
            'name' => 'Prime goes Priority',
            'action' => ShippingRuleAction::UseService->value,
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
