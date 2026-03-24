<?php

namespace App\Http\Controllers;

use App\Services\OAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OAuthCallbackController extends Controller
{
    public function __construct(private readonly OAuthService $oauthService) {}

    /**
     * Handle the broker's redirect back with a transfer code.
     * Claims the tokens server-to-server and stores them.
     */
    public function receive(Request $request, string $provider): RedirectResponse
    {
        // Handle error redirects from the broker
        if ($request->has('error')) {
            logger()->error("OAuth error for {$provider}", [
                'error' => $request->input('error'),
                'description' => $request->input('error_description'),
            ]);

            return redirect()->route('filament.app.pages.settings')
                ->with('oauth_notification', [
                    'status' => 'danger',
                    'title' => 'Connection failed: '.($request->input('error_description') ?: $request->input('error')),
                ]);
        }

        $transferCode = $request->input('transfer_code');

        if (empty($transferCode)) {
            return redirect()->route('filament.app.pages.settings')
                ->with('oauth_notification', [
                    'status' => 'danger',
                    'title' => 'Connection failed: no transfer code received.',
                ]);
        }

        try {
            $this->oauthService->handleReceive($provider, $transferCode);

            return redirect()->route('filament.app.pages.settings')
                ->with('oauth_notification', [
                    'status' => 'success',
                    'title' => ucfirst($provider).' connected successfully.',
                ]);
        } catch (\Throwable $e) {
            logger()->error("OAuth receive failed for {$provider}", [
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
