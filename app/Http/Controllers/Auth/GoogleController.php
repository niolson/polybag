<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OAuthService;
use App\Services\SettingsService;
use App\Services\SsoLoginService;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! app(SettingsService::class)->get('google_sso_enabled', false)) {
            return redirect('/')->with('error', 'Google SSO is not enabled.');
        }

        if ($this->shouldUseBroker()) {
            if (! $this->brokerConfigured()) {
                logger()->error('Google SSO is set to use the OAuth broker but broker_secret or instance_id is missing.');

                return redirect('/login')->withErrors([
                    'data.login' => 'Single sign-on is not configured correctly. Contact your admin.',
                ]);
            }

            return redirect()->away(app(OAuthService::class)->initiateSsoAuthorization('google'));
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        if (! app(SettingsService::class)->get('google_sso_enabled', false)) {
            return redirect('/')->with('error', 'Google SSO is not enabled.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            logger()->error('Google SSO callback failed', ['error' => $e->getMessage()]);

            return redirect('/login')->with('error', 'Google authentication failed. Please try again.');
        }

        // Accounts match by email, so an address Google has not verified proves nothing.
        $raw = $googleUser instanceof AbstractUser ? $googleUser->getRaw() : [];

        if (($raw['email_verified'] ?? false) !== true) {
            logger()->warning('SSO login rejected: Google has not verified the email address');

            return redirect('/login')->withErrors([
                'data.login' => 'Your Google account email address is not verified.',
            ]);
        }

        $email = $googleUser->getEmail();

        $user = User::where('email', $email)->first();

        if (! $user) {
            logger()->warning('SSO login rejected: no account for email returned by Google', [
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

        return app(SsoLoginService::class)->completeLogin($user, 'google');
    }

    private function shouldUseBroker(): bool
    {
        return filled(config('services.oauth.broker_url'))
            && ! config('services.oauth.bypass_broker', false);
    }

    private function brokerConfigured(): bool
    {
        return filled(config('services.oauth.broker_url'))
            && filled(config('services.oauth.broker_secret'))
            && filled(config('services.oauth.instance_id'));
    }
}
