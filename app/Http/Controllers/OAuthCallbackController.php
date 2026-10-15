<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\AmazonMarketplaceDiscoveryResult;
use App\Enums\AmazonMarketplace;
use App\Enums\Role;
use App\Models\CarrierAccount;
use App\Models\DataSource;
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
        abort_unless($request->user()?->role->isAtLeast(Role::Admin), 403);

        $accountId = session()->pull("oauth_account_id.{$provider}");
        $account = $accountId ? CarrierAccount::find($accountId) : null;

        $importSourceId = session()->pull("oauth_data_source_id.{$provider}");
        $importSource = $importSourceId ? DataSource::find($importSourceId) : null;

        // Handle error redirects from the broker
        if ($request->has('error')) {
            session()->forget("oauth_state.{$provider}");

            logger()->error("OAuth error for {$provider}", [
                'error' => $request->input('error'),
                'description' => $request->input('error_description'),
            ]);

            $redirect = $importSource
                ? redirect()->route('filament.app.resources.data-sources.edit', $importSource->id)
                : ($account
                    ? redirect()->route('filament.app.resources.carrier-accounts.edit', $account->id)
                    : redirect()->route('filament.app.pages.settings'));

            return $redirect->with('oauth_notification', [
                'status' => 'danger',
                // The query string is anyone's to write, so only a standard OAuth
                // error code is shown; the provider's description goes to the log.
                'title' => preg_match('/^[a-z_]{1,64}$/', (string) $request->input('error'))
                    ? "Connection failed ({$request->input('error')}). Details are in the log."
                    : 'Connection failed. Details are in the log.',
            ]);
        }

        $transferCode = $request->input('transfer_code');

        if (empty($transferCode)) {
            $redirect = $importSource
                ? redirect()->route('filament.app.resources.data-sources.edit', $importSource->id)
                : ($account
                    ? redirect()->route('filament.app.resources.carrier-accounts.edit', $account->id)
                    : redirect()->route('filament.app.pages.settings'));

            return $redirect->with('oauth_notification', [
                'status' => 'danger',
                'title' => 'Connection failed: no transfer code received.',
            ]);
        }

        try {
            if ($importSource) {
                $discoveryResult = $this->oauthService->handleReceiveForDataSource($provider, $transferCode, $importSource);
                $notification = $this->dataSourceNotification($provider, $discoveryResult);

                return redirect()
                    ->route('filament.app.resources.data-sources.edit', $importSource->id)
                    ->with('oauth_notification', $notification);
            }

            if ($account) {
                $this->oauthService->handleReceiveForAccount($provider, $transferCode, $account);

                return redirect()
                    ->route('filament.app.resources.carrier-accounts.edit', $account->id)
                    ->with('oauth_notification', [
                        'status' => 'success',
                        'title' => $this->providerDisplayName($provider).' connected successfully.',
                    ]);
            }

            // No carrier account or data source in session — nothing to connect to.
            return redirect()->route('filament.app.pages.settings')
                ->with('oauth_notification', [
                    'status' => 'danger',
                    'title' => 'Connection failed: no carrier account or connection to connect.',
                ]);
        } catch (\Throwable $e) {
            logger()->error("OAuth receive failed for {$provider}", [
                'error' => $e->getMessage(),
            ]);

            if ($importSource) {
                return redirect()
                    ->route('filament.app.resources.data-sources.edit', $importSource->id)
                    ->with('oauth_notification', [
                        'status' => 'danger',
                        'title' => 'Connection failed: '.$e->getMessage(),
                    ]);
            }

            $redirectRoute = $account
                ? redirect()->route('filament.app.resources.carrier-accounts.edit', $account->id)
                : redirect()->route('filament.app.pages.settings');

            return $redirectRoute->with('oauth_notification', [
                'status' => 'danger',
                'title' => 'Connection failed: '.$e->getMessage(),
            ]);
        }
    }

    private function providerDisplayName(string $provider): string
    {
        return match ($provider) {
            'sp-api' => 'Amazon',
            'usps' => 'USPS',
            'ups' => 'UPS',
            'fedex' => 'FedEx',
            default => ucfirst($provider),
        };
    }

    /** @return array{status: string, title: string, body?: string} */
    private function dataSourceNotification(
        string $provider,
        ?AmazonMarketplaceDiscoveryResult $discoveryResult,
    ): array {
        if ($provider !== 'sp-api' || $discoveryResult === null) {
            return [
                'status' => 'success',
                'title' => $this->providerDisplayName($provider).' connected successfully.',
            ];
        }

        if (! $discoveryResult->succeeded) {
            return [
                'status' => 'warning',
                'title' => 'Amazon connected, but marketplaces could not be retrieved.',
                'body' => $discoveryResult->error ?? 'Use Refresh Amazon Marketplaces to try again.',
            ];
        }

        if ($discoveryResult->selectedMarketplaceUnavailable) {
            return [
                'status' => 'warning',
                'title' => 'Amazon connected, but the selected marketplace was not returned.',
                'body' => 'The existing marketplace selection was preserved. Review it before changing imports.',
            ];
        }

        if ($discoveryResult->marketplaces === []) {
            return [
                'status' => 'warning',
                'title' => 'Amazon connected, but no supported marketplaces were found.',
                'body' => 'PolyBag currently supports Amazon retail marketplaces in North America.',
            ];
        }

        if ($discoveryResult->selectionRequired) {
            return [
                'status' => 'warning',
                'title' => 'Amazon connected. Choose a marketplace before importing.',
            ];
        }

        $marketplace = AmazonMarketplace::tryFrom((string) $discoveryResult->selectedMarketplaceId);

        if (count($discoveryResult->marketplaces) === 1 && $marketplace !== null) {
            return [
                'status' => 'success',
                'title' => "Amazon connected. {$marketplace->label()} ({$marketplace->countryCode()}) was selected automatically.",
            ];
        }

        return [
            'status' => 'success',
            'title' => 'Amazon connected successfully.',
        ];
    }
}
