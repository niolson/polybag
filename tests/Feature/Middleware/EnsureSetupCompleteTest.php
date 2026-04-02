<?php

use App\Enums\Role;
use App\Filament\Pages\SetupWizard;
use App\Http\Middleware\EnsureSetupComplete;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

function setSetupComplete(bool $isComplete): void
{
    Setting::updateOrCreate(
        ['key' => 'setup_complete'],
        ['value' => $isComplete ? '1' : '0', 'type' => 'boolean', 'group' => 'system'],
    );

    app(SettingsService::class)->clearCache();
}

it('redirects admins to the setup wizard when setup is incomplete', function (): void {
    setSetupComplete(false);

    $request = Request::create('/', 'GET');
    $request->setUserResolver(fn (): User => User::factory()->create(['role' => Role::Admin]));

    $response = app(EnsureSetupComplete::class)->handle(
        $request,
        fn () => response('ok'),
    );

    expect($response->isRedirect())->toBeTrue()
        ->and($response->headers->get('Location'))->toBe(SetupWizard::getUrl());
});

it('returns 503 for non-admin users while setup is incomplete', function (): void {
    setSetupComplete(false);

    $request = Request::create('/', 'GET');
    $request->setUserResolver(fn (): User => User::factory()->create(['role' => Role::User]));

    expect(fn () => app(EnsureSetupComplete::class)->handle(
        $request,
        fn () => response('ok'),
    ))->toThrow(HttpException::class, 'Application setup is in progress');
});

it('bypasses setup enforcement for exempt paths', function (): void {
    setSetupComplete(false);

    $request = Request::create('/oauth/test/receive', 'GET');

    $response = app(EnsureSetupComplete::class)->handle(
        $request,
        fn () => response('ok'),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('ok');
});

it('allows requests through once setup is complete', function (): void {
    setSetupComplete(true);

    $request = Request::create('/', 'GET');
    $request->setUserResolver(fn (): User => User::factory()->create(['role' => Role::User]));

    $response = app(EnsureSetupComplete::class)->handle(
        $request,
        fn () => response('ok'),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('ok');
});
