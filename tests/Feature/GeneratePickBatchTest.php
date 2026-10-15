<?php

use App\Enums\Role;
use App\Filament\Pages\GeneratePickBatch;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Manager]));
    Setting::create(['key' => 'picking_enabled', 'value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

it('generates a batch when multi-client is disabled and the client_id field is hidden', function (): void {
    // multi_client_enabled defaults to false, so the client_id field is not visible
    // and therefore absent from the form state. Generating must not error on the
    // missing array key.
    Livewire::test(GeneratePickBatch::class)
        ->call('generate')
        ->assertHasNoErrors()
        ->assertNotified('No Open Shipments');
});
