<?php

use App\Enums\Role;
use App\Filament\Widgets\MfaRecommendedWidget;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the MFA nudge to admins while require_mfa is off', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    expect(MfaRecommendedWidget::canView())->toBeTrue();
});

it('hides the MFA nudge once require_mfa is on', function (): void {
    app(SettingsService::class)->set('require_mfa', true, 'boolean');
    app(SettingsService::class)->clearCache();

    $this->actingAs(User::factory()->admin()->create());

    expect(MfaRecommendedWidget::canView())->toBeFalse();
});

it('hides the MFA nudge from non-admins', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::Packer]));

    expect(MfaRecommendedWidget::canView())->toBeFalse();
});

it('hides the MFA nudge once it has been dismissed app-wide', function (): void {
    app(SettingsService::class)->set('mfa_recommendation_dismissed', true, 'boolean');
    app(SettingsService::class)->clearCache();

    $this->actingAs(User::factory()->admin()->create());

    expect(MfaRecommendedWidget::canView())->toBeFalse();
});

it('persists an app-wide dismissal when an admin dismisses it', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    expect(MfaRecommendedWidget::canView())->toBeTrue();

    Livewire::test(MfaRecommendedWidget::class)
        ->assertSee('show this again')
        ->call('dismiss')
        ->assertSet('dismissed', true)
        ->assertDontSee('show this again');

    expect((bool) app(SettingsService::class)->get('mfa_recommendation_dismissed', false))->toBeTrue()
        ->and(MfaRecommendedWidget::canView())->toBeFalse();
});
