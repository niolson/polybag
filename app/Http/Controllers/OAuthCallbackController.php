<?php

namespace App\Http\Controllers;

use App\Services\OAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OAuthCallbackController extends Controller
{
    public function __construct(private readonly OAuthService $oauthService) {}

    public function callback(Request $request, string $provider): RedirectResponse
    {
        try {
            $this->oauthService->handleCallback($provider, $request->query());

            return redirect()->route('filament.app.pages.settings')
                ->with('oauth_notification', [
                    'status' => 'success',
                    'title' => ucfirst($provider).' connected successfully.',
                ]);
        } catch (\Throwable $e) {
            logger()->error("OAuth callback failed for {$provider}", [
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('filament.app.pages.settings')
                ->with('oauth_notification', [
                    'status' => 'danger',
                    'title' => 'Connection failed: '.$e->getMessage(),
                ]);
        }
    }
}
