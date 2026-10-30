<?php

use App\Enums\Role;
use App\Enums\ShipmentStatus;
use App\Filament\Widgets\OpenShipmentsPerClientWidget;
use App\Models\Client;
use App\Models\Shipment;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(SettingsService::class)->clearCache();
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

it('is hidden when multi_client_enabled is false', function (): void {
    $this->actingAs(User::factory()->create());

    expect(OpenShipmentsPerClientWidget::canView())->toBeFalse();
});

it('is visible when multi_client_enabled is true', function (): void {
    config()->set('features.multi_client_enabled', true);

    $this->actingAs(User::factory()->create(['role' => Role::Manager]));

    expect(OpenShipmentsPerClientWidget::canView())->toBeTrue();
});

it('is hidden from shippers even when multi_client_enabled is true', function (): void {
    config()->set('features.multi_client_enabled', true);

    $this->actingAs(User::factory()->create(['role' => Role::Packer]));

    expect(OpenShipmentsPerClientWidget::canView())->toBeFalse();
});

it('shows client names and open shipment counts', function (): void {
    config()->set('features.multi_client_enabled', true);

    $clientA = Client::factory()->create(['name' => 'Acme Corp', 'active' => true]);
    $clientB = Client::factory()->create(['name' => 'Beta LLC', 'active' => true]);

    Shipment::factory()->count(3)->create(['client_id' => $clientA->id, 'status' => ShipmentStatus::Open]);
    Shipment::factory()->count(1)->create(['client_id' => $clientA->id, 'status' => ShipmentStatus::Shipped]);
    Shipment::factory()->count(2)->create(['client_id' => $clientB->id, 'status' => ShipmentStatus::Open]);

    Livewire::actingAs(User::factory()->create())
        ->test(OpenShipmentsPerClientWidget::class)
        ->assertSee('Acme Corp')
        ->assertSee('Beta LLC')
        ->assertSee('3')
        ->assertSee('2');
});

it('excludes inactive clients', function (): void {
    config()->set('features.multi_client_enabled', true);

    $active = Client::factory()->create(['name' => 'Active Co', 'active' => true]);
    $inactive = Client::factory()->create(['name' => 'Inactive Co', 'active' => false]);

    Shipment::factory()->create(['client_id' => $active->id, 'status' => ShipmentStatus::Open]);
    Shipment::factory()->create(['client_id' => $inactive->id, 'status' => ShipmentStatus::Open]);

    Livewire::actingAs(User::factory()->create())
        ->test(OpenShipmentsPerClientWidget::class)
        ->assertSee('Active Co')
        ->assertDontSee('Inactive Co');
});

it('shows zero for clients with no open shipments', function (): void {
    config()->set('features.multi_client_enabled', true);

    $client = Client::factory()->create(['name' => 'Empty Client', 'active' => true]);
    Shipment::factory()->create(['client_id' => $client->id, 'status' => ShipmentStatus::Shipped]);

    Livewire::actingAs(User::factory()->create())
        ->test(OpenShipmentsPerClientWidget::class)
        ->assertSee('Empty Client')
        ->assertSee('0');
});
