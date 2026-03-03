<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Rebuild today's summary stats every 15 minutes during business hours
        $schedule->command('stats:aggregate --today')
            ->everyFifteenMinutes()
            ->between('6:00', '22:00')
            ->withoutOverlapping();

        // Full rebuild of yesterday + today at midnight (includes histogram refresh)
        $schedule->command('stats:aggregate')
            ->dailyAt('00:05')
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
