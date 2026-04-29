<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsManager;
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

        // Purge old audit logs, rate quotes, and notifications
        $schedule->command('data:purge')
            ->dailyAt('01:00')
            ->withoutOverlapping();

        // Purge PII from shipped shipments past retention period
        $schedule->command('shipments:purge-pii')
            ->dailyAt('01:30')
            ->withoutOverlapping();

        // Archive old shipped shipments (checks if archiving is enabled)
        $schedule->command('shipments:archive')
            ->weeklyOn(Schedule::SUNDAY, '02:00')
            ->withoutOverlapping();

        // Proactively refresh OAuth tokens to prevent expiry
        $schedule->command('oauth:refresh')
            ->weeklyOn(Schedule::WEDNESDAY, '03:00')
            ->withoutOverlapping();

        $schedule->command('packages:refresh-tracking')
            ->everyFourHours()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo(fn () => route('filament.app.auth.login'));
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'manager' => EnsureUserIsManager::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
