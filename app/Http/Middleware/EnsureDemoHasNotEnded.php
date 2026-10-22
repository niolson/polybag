<?php

namespace App\Http\Middleware;

use App\Services\DemoLifetime;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs everyone out once a demo has ended ({@see DemoLifetime}), so a session
 * opened before the end, or one an SSO sign-in starts after it, cannot keep
 * working in a tenant that is waiting to be torn down.
 *
 * It runs on every web route, not just the panel's: pack slips, pick lists
 * and print acknowledgments are served outside Filament and show order data.
 * A request with nobody signed in passes, so the sign-in page can say the
 * demo has ended instead of redirecting to itself.
 */
class EnsureDemoHasNotEnded
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(DemoLifetime::class)->hasEnded() || ! Auth::check()) {
            return $next($request);
        }

        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'This demo has ended.'], 401);
        }

        return redirect()->route('filament.app.auth.login');
    }
}
