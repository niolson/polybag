<?php

use App\Filament\Pages\Auth\Login;
use App\Models\DataSource;
use App\Models\Location;
use App\Models\Shipment;
use App\Models\User;
use App\Services\DemoLifetime;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * `live-store-demo/04`: a demo's time limit, `demo.expires_at`. Every page
 * says when it ends; past it nothing imports and nobody can sign in, so a
 * teardown that runs late leaves no window for new orders to arrive.
 */
beforeEach(function (): void {
    $this->app['env'] = 'demo';
    Carbon::setTestNow('2026-10-22 15:00:00');
});

afterEach(fn () => Carbon::setTestNow());

it('reads when the demo ends', function (?string $configured, ?string $endsAt, bool $ended): void {
    config()->set('demo.expires_at', $configured);
    $lifetime = app(DemoLifetime::class);

    expect($lifetime->endsAt()?->toIso8601String())->toBe($endsAt)
        ->and($lifetime->hasEnded())->toBe($ended);
})->with([
    'no limit' => [null, null, false],
    'later' => ['2026-10-24 17:00', '2026-10-24T17:00:00+00:00', false],
    'with a zone' => ['2026-10-22T09:00:00-07:00', '2026-10-22T09:00:00-07:00', false],
    'passed' => ['2026-10-22 14:59:59', '2026-10-22T14:59:59+00:00', true],
    'unreadable' => ['next tuesday-ish', '1970-01-01T00:00:00+00:00', true],
]);

it('has no end outside Demo, whatever the environment says', function (string $environment): void {
    $this->app['env'] = $environment;
    config()->set('demo.expires_at', '2026-01-01 00:00');

    expect(app(DemoLifetime::class)->endsAt())->toBeNull()
        ->and(app(DemoLifetime::class)->hasEnded())->toBeFalse();
})->with(['production', 'local']);

it('stops every import path once the demo has ended', function (): void {
    $shopify = DataSource::factory()->shopify()->create(['active' => true, 'import_enabled' => true]);

    config()->set('demo.expires_at', '2026-10-23 00:00');
    expect(DataSource::importing()->pluck('id')->all())->toBe([$shopify->id])
        ->and($shopify->importsOrders())->toBeTrue();

    config()->set('demo.expires_at', '2026-10-22 12:00');
    expect(DataSource::importing()->count())->toBe(0)
        ->and($shopify->importsOrders())->toBeFalse();

    $this->artisan('shipments:import', ['--source-id' => $shopify->id])
        ->expectsOutputToContain('This demo has ended')
        ->assertSuccessful();
    $this->artisan('shipments:import', ['--all' => true])
        ->expectsOutputToContain('No active import sources')
        ->assertSuccessful();
});

it('says on every page when the demo ends, in the user\'s Location timezone', function (): void {
    config()->set('demo.expires_at', '2026-10-24 21:00');
    Location::factory()->create(['is_default' => true, 'timezone' => 'America/New_York']);
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/')
        ->assertOk()
        ->assertSee('This demo ends Oct 24 at 5:00 pm EDT.');
});

it('shows no banner on a demo with no limit', function (): void {
    config()->set('demo.expires_at', null);
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/')
        ->assertOk()
        ->assertDontSee('This demo ends');
});

it('replaces the sign-in form once the demo has ended, and refuses a sign-in', function (): void {
    config()->set('demo.expires_at', '2026-10-22 12:00');
    User::factory()->create(['email' => 'prospect@example.com', 'password' => 'correct-horse']);

    Livewire::test(Login::class)
        ->assertSee('This demo has ended')
        ->assertDontSee('Email or Username')
        ->set('data.login', 'prospect@example.com')
        ->set('data.password', 'correct-horse')
        ->call('authenticate')
        ->assertHasErrors(['data.login']);

    expect(auth()->check())->toBeFalse();
});

it('signs out a session that outlives the demo', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    config()->set('demo.expires_at', '2026-10-22 12:00');

    $this->get('/')->assertRedirect(Filament::getLoginUrl());

    expect(auth()->check())->toBeFalse();
});

it('ends access outside the panel too, where pack slips show order data', function (): void {
    $shipment = Shipment::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
    config()->set('demo.expires_at', '2026-10-22 12:00');

    $this->get("/shipments/{$shipment->id}/pack-slip")->assertRedirect(Filament::getLoginUrl());

    expect(auth()->check())->toBeFalse();
});

it('answers a background request from an outlived session with a 401', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    config()->set('demo.expires_at', '2026-10-22 12:00');
    // A page sends its CSRF token; without one the request is refused before this guard.
    $this->withoutMiddleware(PreventRequestForgery::class);

    $this->postJson('/pack-slips/printed', [])
        ->assertUnauthorized()
        ->assertJson(['message' => 'This demo has ended.']);
});

it('still shows the sign-in page to a visitor after the demo has ended', function (): void {
    config()->set('demo.expires_at', '2026-10-22 12:00');

    $this->get(Filament::getLoginUrl())
        ->assertOk()
        ->assertSee('This demo has ended');
});
