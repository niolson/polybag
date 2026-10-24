<?php

use App\Support\FatalErrorHeadroom;

beforeEach(function (): void {
    $this->originalLimit = ini_get('memory_limit');
});

afterEach(function (): void {
    ini_set('memory_limit', $this->originalLimit);
});

it('raises the limit to what the process holds plus the headroom', function (): void {
    ini_set('memory_limit', (string) (memory_get_usage(true) + 1024 * 1024));

    FatalErrorHeadroom::raise();

    expect((int) ini_get('memory_limit'))->toBeGreaterThanOrEqual(memory_get_usage(true) + FatalErrorHeadroom::BYTES - 1024 * 1024);
});

it('never lowers a limit that already has the room', function (): void {
    ini_set('memory_limit', '8G');

    FatalErrorHeadroom::raise();

    expect(ini_get('memory_limit'))->toBe('8G');
});

it('leaves an unlimited process alone', function (): void {
    ini_set('memory_limit', '-1');

    FatalErrorHeadroom::raise();

    expect(ini_get('memory_limit'))->toBe('-1');
});
