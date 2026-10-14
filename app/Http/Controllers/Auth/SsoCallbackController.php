<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\SsoLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SsoCallbackController extends Controller
{
    public function receive(Request $request, string $provider): RedirectResponse
    {
        $enabledSetting = match ($provider) {
            'google' => 'google_sso_enabled',
            'azure' => 'azure_sso_enabled',
            default => null,
        };

        if (! $enabledSetting || ! app(SettingsService::class)->get($enabledSetting, false)) {
            return redirect('/login')->withErrors(['data.login' => 'SSO is not enabled.']);
        }

        if ($request->has('error')) {
            logger()->warning("SSO broker error for {$provider}", [
                'error' => $request->input('error'),
                'description' => $request->input('error_description'),
            ]);

            return redirect('/login')->withErrors([
                'data.login' => 'Authentication failed. Please try again.',
            ]);
        }

        $transferCode = $request->input('transfer_code');

        if (empty($transferCode)) {
            return redirect('/login')->withErrors(['data.login' => 'Authentication failed. Please try again.']);
        }

        try {
            $data = $this->claimTransferCode($transferCode);
        } catch (\Throwable $e) {
            logger()->error("SSO claim failed for {$provider}", ['error' => $e->getMessage()]);

            return redirect('/login')->withErrors(['data.login' => 'Authentication failed. Please try again.']);
        }

        $expectedNonce = session()->pull("oauth_state.{$provider}");

        if (empty($expectedNonce) || ! hash_equals($expectedNonce, $data['nonce'] ?? '')) {
            return redirect('/login')->withErrors(['data.login' => 'Authentication failed. Please try again.']);
        }

        // The broker's app registration accepts any Microsoft tenant, and accounts
        // match by email, so only a tenant this install allows may sign in. The
        // broker forwards `tid` only from an ID token it verified.
        if ($provider === 'azure' && ! app(SsoLoginService::class)->azureTenantAllowed($data['extra']['tid'] ?? null)) {
            logger()->warning('SSO login rejected: Microsoft tenant is not allowed', [
                'tid' => $data['extra']['tid'] ?? null,
            ]);

            return redirect('/login')->withErrors([
                'data.login' => 'Your Microsoft organization is not allowed to sign in here. Contact your admin.',
            ]);
        }

        $email = $data['extra']['user_email'] ?? null;

        if (empty($email)) {
            logger()->error("SSO broker claim missing user_email for {$provider}");

            return redirect('/login')->withErrors([
                'data.login' => 'Could not retrieve your email from the identity provider.',
            ]);
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            logger()->warning("SSO login rejected: no account for email returned by {$provider} broker", [
                'email' => $email,
            ]);

            return redirect('/login')->withErrors([
                'data.login' => 'No account found for this email. Contact your admin.',
            ]);
        }

        if (! $user->active) {
            return redirect('/login')->withErrors([
                'data.login' => 'This account is disabled. Contact your admin.',
            ]);
        }

        // Log-only pilot: capture the IdP's authentication-strength assertion so
        // we can confirm what each provider returns before trusting it to satisfy
        // MFA (see the sso-mfa-federation "trust IdP MFA" slice). Not yet gated on.
        logger()->info("SSO login MFA assertion for {$provider}", [
            'email' => $email,
            'amr' => $data['extra']['amr'] ?? null,
            'auth_time' => $data['extra']['auth_time'] ?? null,
            // `tid` (Azure) is only forwarded when the ID token verified; its
            // presence distinguishes "verified but IdP sent no amr" from
            // "verification failed → fell back to Graph". `extra_keys` shows
            // exactly what the broker returned. Diagnostic — trim once tuned.
            'tid' => $data['extra']['tid'] ?? null,
            'extra_keys' => array_keys($data['extra'] ?? []),
        ]);

        return app(SsoLoginService::class)->completeLogin($user, $provider, $data['extra'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function claimTransferCode(string $transferCode): array
    {
        $brokerUrl = config('services.oauth.broker_url');
        $brokerSecret = config('services.oauth.broker_secret');
        $instanceId = config('services.oauth.instance_id');

        $signature = hash_hmac('sha256', $transferCode, $brokerSecret);

        $response = Http::post(rtrim($brokerUrl, '/').'/oauth/claim', [
            'transfer_code' => $transferCode,
            'instance_id' => $instanceId,
            'signature' => $signature,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to claim SSO token from broker: '.$response->body());
        }

        return $response->json();
    }
}
