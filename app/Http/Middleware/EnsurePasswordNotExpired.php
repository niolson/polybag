<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Auth\ChangePassword;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a user with an expired password pinned to the change-password page.
 *
 * The `password_expired` session flag is set at login (see Login::redirectToPasswordChange).
 * Until the user actually changes their password (which forgets the flag), panel
 * requests are redirected back to the change-password page. Required MFA enrollment
 * remains reachable because Filament requires it before opening that page.
 */
class EnsurePasswordNotExpired
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('password_expired')) {
            return $next($request);
        }

        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        return redirect()->to(ChangePassword::getUrl());
    }

    private function shouldBypass(Request $request): bool
    {
        $path = trim($request->path(), '/');

        // Filament gates the password page on MFA enrollment. Blocking enrollment
        // here would send the user back and forth between the two requirements.
        if ($path === 'multi-factor-authentication/set-up') {
            return true;
        }

        // Allow only the change-password page itself and logout. This guard is
        // registered as Livewire persistent middleware, so on component updates
        // Livewire replays it against the *original page path* (memo.path) — the
        // change-password page passes here, while a stale snapshot on any other
        // page is redirected. wire:navigate loads other pages with a normal GET,
        // which also hits the redirect above.
        $exemptPrefixes = [
            'auth/change-password',
            'logout',
        ];

        foreach ($exemptPrefixes as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
