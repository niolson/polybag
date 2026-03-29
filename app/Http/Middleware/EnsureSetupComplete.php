<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Filament\Pages\SetupWizard;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSetupComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        if (app(SettingsService::class)->get('setup_complete', false)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user?->role->isAtLeast(Role::Admin)) {
            return redirect()->to(SetupWizard::getUrl());
        }

        abort(503, 'Application setup is in progress. Please check back shortly.');
    }

    private function shouldBypass(Request $request): bool
    {
        $path = trim($request->path(), '/');

        // Allow the wizard itself, auth pages, OAuth callbacks, and QZ signing
        $exemptPrefixes = [
            'setup',
            'login',
            'logout',
            'auth/',
            'oauth/',
            'qz/',
        ];

        foreach ($exemptPrefixes as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
