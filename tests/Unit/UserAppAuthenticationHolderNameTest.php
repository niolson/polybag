<?php

use App\Models\User;

it('qualifies the authenticator account label with the instance host', function (): void {
    config(['app.url' => 'https://demo.polybag.app']);

    $user = new User(['username' => 'presenter']);

    expect($user->getAppAuthenticationHolderName())->toBe('presenter (demo.polybag.app)');
});

it('prefers the email and drops any port from the host', function (): void {
    config(['app.url' => 'https://polybag.test:8443/']);

    $user = new User(['email' => 'packer@example.com', 'username' => 'packer']);

    expect($user->getAppAuthenticationHolderName())->toBe('packer@example.com (polybag.test)');
});

it('falls back to the bare holder when the app url has no host', function (): void {
    config(['app.url' => '']);

    $user = new User(['username' => 'presenter']);

    expect($user->getAppAuthenticationHolderName())->toBe('presenter');
});
