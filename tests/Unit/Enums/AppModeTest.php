<?php

use App\Enums\AppMode;

it('resolves the mode from APP_ENV', function (string $environment, AppMode $mode): void {
    $this->app['env'] = $environment;

    expect(AppMode::current())->toBe($mode);
})->with([
    'production' => ['production', AppMode::Production],
    'demo' => ['demo', AppMode::Demo],
    'local' => ['local', AppMode::Dev],
    'testing' => ['testing', AppMode::Dev],
    'an unknown value fails closed' => ['staging', AppMode::Production],
]);
