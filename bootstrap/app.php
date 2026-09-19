<?php

use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

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

        $schedule->command('packages:refresh-tracking')
            ->everyFourHours()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust only the reverse-proxy chain in front of the app — the internal
        // Docker hops (Caddy/nginx) and the Cloudflare edge — so a client-supplied
        // X-Forwarded-For can't spoof the perceived IP (which `at: '*'` allowed).
        // The Cloudflare ranges are published at the URL below and are only
        // relevant when the app sits behind Cloudflare; keep them in sync if
        // Cloudflare ever updates its ranges. Deployments not using Cloudflare
        // can leave them — they match no client the internal hops would forward.
        $middleware->trustProxies(at: [
            // Internal reverse-proxy hops (Docker private networks)
            '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7',
            // Cloudflare IPv4 (https://www.cloudflare.com/ips/)
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            // Cloudflare IPv6
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ]);
        $middleware->redirectGuestsTo(fn (): string => route('filament.app.auth.login'));
        $middleware->append(ContentSecurityPolicy::class);
        // polybag_last_instance is read by polybag-connect, a separate app with
        // its own APP_KEY — Laravel's default cookie encryption would make it
        // undecipherable there, defeating the whole point of the cookie.
        $middleware->encryptCookies(except: ['polybag_last_instance']);
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'manager' => EnsureUserIsManager::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
    })->create();
