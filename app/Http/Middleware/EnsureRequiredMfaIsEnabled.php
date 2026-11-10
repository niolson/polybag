<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Illuminate\Http\Request;

/**
 * Filament's required-MFA middleware, gated on the `require_mfa` setting at
 * request time.
 *
 * Filament reads its "MFA required" flag only when it registers the panel's
 * routes: the flag decides whether pages carry the enrollment middleware and
 * whether the set-up page exists. Routes are cached when a container starts,
 * so a flag read from settings there took effect only at the next restart, and
 * turning on Require MFA in Settings enforced nothing until then. The panel now
 * always registers both, and this middleware decides on every request.
 */
class EnsureRequiredMfaIsEnabled extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! self::isRequired()) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    /**
     * Fails open, as the panel's old route-time check did: a settings store
     * that cannot be read must not lock everyone out of the app.
     */
    public static function isRequired(): bool
    {
        try {
            return (bool) app(SettingsService::class)->get('require_mfa', false);
        } catch (\Throwable) {
            return false;
        }
    }
}
