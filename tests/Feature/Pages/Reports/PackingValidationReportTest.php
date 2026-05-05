<?php

use App\Enums\Deliverability;
use App\Enums\LabelBatchItemStatus;
use App\Enums\Role;
use App\Filament\Pages\Reports\PackingValidationReport;
use App\Models\LabelBatchItem;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders packing validation report page', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    Livewire::actingAs($user)
        ->test(PackingValidationReport::class)
        ->assertOk()
        ->assertSee('Packing Validation');
});

it('defaults to weight mismatches section', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    $component = Livewire::actingAs($user)
        ->test(PackingValidationReport::class);

    expect($component->get('section'))->toBe('weight_mismatches');
});

it('switches to batch failures section', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    LabelBatchItem::factory()->create(['status' => LabelBatchItemStatus::Failed]);

    Livewire::actingAs($user)
        ->test(PackingValidationReport::class)
        ->set('section', 'batch_failures')
        ->assertOk();
});

it('switches to validation issues section', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    $shipment = Shipment::factory()->create(['deliverability' => Deliverability::No]);
    Package::factory()->shipped()->create([
        'shipment_id' => $shipment->id,
        'shipped_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(PackingValidationReport::class)
        ->set('section', 'validation_issues')
        ->assertOk();
});

it('counts batch failures', function (): void {
    $user = User::factory()->create(['role' => Role::Manager]);

    LabelBatchItem::factory()->count(3)->create(['status' => LabelBatchItemStatus::Failed]);
    LabelBatchItem::factory()->create(['status' => LabelBatchItemStatus::Success]);

    $component = Livewire::actingAs($user)->test(PackingValidationReport::class);

    expect($component->instance()->getBatchFailureCount())->toBe(3);
});

it('restricts access to managers and above', function (): void {
    $user = User::factory()->create(['role' => Role::User]);
    $this->actingAs($user);

    expect(PackingValidationReport::canAccess())->toBeFalse();

    $manager = User::factory()->create(['role' => Role::Manager]);
    $this->actingAs($manager);

    expect(PackingValidationReport::canAccess())->toBeTrue();
});
