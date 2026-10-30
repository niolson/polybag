<?php

use App\Enums\Role;
use App\Filament\Widgets\DatabaseHealthWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('is visible to admin users', function (): void {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(DatabaseHealthWidget::class)
        ->assertSee('Database Health');
});

it('canView returns false for non-admin', function (): void {
    $user = User::factory()->create(['role' => Role::Packer]);

    $this->actingAs($user);

    expect(DatabaseHealthWidget::canView())->toBeFalse();
});

it('canView returns true for admin', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    expect(DatabaseHealthWidget::canView())->toBeTrue();
});

it('shows all four table stats', function (): void {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(DatabaseHealthWidget::class)
        ->assertSee('Shipments')
        ->assertSee('Packages')
        ->assertSee('Rate Quotes')
        ->assertSee('Audit Logs');
});

it('shows healthy description for low row counts', function (): void {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(DatabaseHealthWidget::class)
        ->assertSee('Healthy');
});
