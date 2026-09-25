<?php

use App\Filament\Widgets\DeviceStatusWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders without errors', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DeviceStatusWidget::class)
        ->assertStatus(200);
});

it('loads PrinterSettings with the page, not inside the lazily loaded widget', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/')
        ->assertOk()
        ->assertSee('window.PrinterSettings = (function', false);

    Livewire::test(DeviceStatusWidget::class)
        ->assertDontSee('window.PrinterSettings', false);
});
