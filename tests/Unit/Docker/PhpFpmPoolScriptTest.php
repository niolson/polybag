<?php

use Illuminate\Support\Facades\Process;

/**
 * `docker/php-fpm-pool.sh` sizes the image's php-fpm pool at container
 * start (`capacity-audit/01`). php-fpm refuses to start when a spare-server
 * count exceeds the maximum, so a small pool must scale those down too.
 *
 * @return array{exitCode: int, output: string, error: string}
 */
function phpFpmPool(?string $maxChildren): array
{
    // False unsets the variable, whatever the shell running the tests has.
    $result = Process::env(['PHP_FPM_MAX_CHILDREN' => $maxChildren ?? false])->run(['bash', base_path('docker/php-fpm-pool.sh')]);

    return ['exitCode' => $result->exitCode() ?? 1, 'output' => $result->output(), 'error' => $result->errorOutput()];
}

it('defaults to twenty workers, when unset or empty', function (?string $value): void {
    $pool = phpFpmPool($value);

    expect($pool['exitCode'])->toBe(0)
        ->and($pool['output'])->toBe(<<<'CONF'
            [www]
            pm = dynamic
            pm.max_children = 20
            pm.start_servers = 4
            pm.min_spare_servers = 2
            pm.max_spare_servers = 6

            CONF);
})->with([null, '']);

it('takes the pool size from the environment', function (): void {
    expect(phpFpmPool('40')['output'])->toContain("pm.max_children = 40\n")
        ->toContain("pm.max_spare_servers = 6\n");
});

it('never sets a spare-server count above the maximum', function (): void {
    expect(phpFpmPool('1')['output'])
        ->toContain("pm.max_children = 1\n")
        ->toContain("pm.start_servers = 1\n")
        ->toContain("pm.min_spare_servers = 1\n")
        ->toContain("pm.max_spare_servers = 1\n");
});

it('refuses a value that is not a positive whole number', function (string $value): void {
    $pool = phpFpmPool($value);

    expect($pool['exitCode'])->not->toBe(0)
        ->and($pool['output'])->toBe('')
        ->and($pool['error'])->toContain('PHP_FPM_MAX_CHILDREN must be a positive whole number');
})->with(['0', '-5', '2.5', 'twenty', '20 ']);
