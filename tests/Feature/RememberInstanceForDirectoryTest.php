<?php

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('remembers this subdomain in a directory cookie when instance_cookie_domain is configured', function (): void {
    config(['app.instance_cookie_domain' => '.polybag.app']);

    $user = User::factory()->create();

    event(new Login('web', $user, false));

    expect(Cookie::hasQueued('polybag_last_instance'))->toBeTrue();

    $cookie = Cookie::queued('polybag_last_instance');
    expect($cookie->getDomain())->toBe('.polybag.app')
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getValue())->not->toBeEmpty();
});

it('sets no directory cookie when instance_cookie_domain is not configured', function (): void {
    config(['app.instance_cookie_domain' => null]);

    $user = User::factory()->create();

    event(new Login('web', $user, false));

    expect(Cookie::hasQueued('polybag_last_instance'))->toBeFalse();
});

it('sends the directory cookie unencrypted on the wire, since polybag-connect has a different APP_KEY and cannot decrypt it', function (): void {
    config(['app.instance_cookie_domain' => '.polybag.app']);

    $user = User::factory()->create();

    // A real HTTP round trip through the full 'web' middleware stack —
    // including EncryptCookies — is required here: firing the Login event
    // directly (as the tests above do) bypasses that middleware entirely and
    // would pass even if this cookie were being encrypted.
    Route::middleware('web')->get('/__test-login', function () use ($user) {
        auth()->login($user);

        return response('ok');
    });

    $response = $this->get('/__test-login');

    $expectedHost = parse_url(config('app.url'), PHP_URL_HOST);
    $response->assertPlainCookie('polybag_last_instance', $expectedHost);
});
