<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\AccountLockoutService;
use App\Services\DemoLifetime;
use App\Services\PasswordPolicyService;
use App\Services\SettingsService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\MultiFactor\Contracts\HasBeforeChallengeHook;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    /**
     * When an SSO login is pending a second factor (see SsoLoginService), the
     * user is not yet authenticated — only their id is stashed in the session.
     * Put the login page straight into MFA-challenge mode for that user, reusing
     * Filament's challenge form. Authentication happens in authenticate() once
     * the challenge passes.
     */
    public function mount(): void
    {
        parent::mount();

        $pendingUserId = session('sso_mfa.user_id');

        if ($pendingUserId === null || Filament::auth()->check()) {
            return;
        }

        $user = User::find($pendingUserId);

        if (! $user) {
            session()->forget(['sso_mfa.user_id', 'sso_mfa.remember']);

            return;
        }

        $this->userUndertakingMultiFactorAuthentication = encrypt($user->getAuthIdentifier());

        $this->prepareSsoChallenge($user);

        $this->multiFactorChallengeForm->fill();
    }

    /**
     * Once a demo has ended, the page says so in place of the form
     * (`live-store-demo/04`).
     */
    public function content(Schema $schema): Schema
    {
        if (! app(DemoLifetime::class)->hasEnded()) {
            return parent::content($schema);
        }

        return $schema->components([
            Callout::make('This demo has ended')
                ->key('demo_ended')
                ->warning()
                ->description('Its orders and store connection are being deleted. Thank you for trying PolyBag.'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('login')
                    ->label('Email or Username')
                    ->required()
                    ->autocomplete()
                    ->autofocus()
                    ->extraInputAttributes(['tabindex' => 1]),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
            ]);
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        $login = $data['login'];
        $field = str_contains($login, '@') ? 'email' : 'username';

        return [
            $field => $login,
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }

    /**
     * After successful authentication, check for password expiration
     * and SSO-only users. Also enforces account lockout: too many
     * consecutive failed attempts locks the account for a configured
     * duration, independent of Filament's IP-based rate limiting.
     */
    public function authenticate(): ?LoginResponse
    {
        if (app(DemoLifetime::class)->hasEnded()) {
            throw ValidationException::withMessages([
                'data.login' => 'This demo has ended.',
            ]);
        }

        // A pending SSO login submits the MFA challenge here, not credentials.
        if (session()->has('sso_mfa.user_id')) {
            return $this->completeSsoMfaChallenge();
        }

        $data = $this->form->getState();
        $login = $data['login'];
        $field = str_contains($login, '@') ? 'email' : 'username';

        // Check if user exists and is SSO-only (no password)
        $user = User::where($field, $login)->first();

        if ($user && ! $user->hasLocalPassword()) {
            throw ValidationException::withMessages([
                'data.login' => 'This account uses SSO. Use the sign-in button below.',
            ]);
        }

        $lockout = app(AccountLockoutService::class);

        if ($user && $lockout->isLocked($user)) {
            throw ValidationException::withMessages([
                'data.login' => $lockout->lockoutMessage($user),
            ]);
        }

        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            if ($user) {
                $lockout->recordFailedAttempt($user);
            }

            throw $exception;
        }

        // A null response with no exception can mean credentials were valid
        // but an MFA challenge is pending, or that the request was IP-rate-
        // limited before credentials were ever checked. Only reset the
        // account-level counter in the former case.
        if ($user && (auth()->check() || filled($this->userUndertakingMultiFactorAuthentication))) {
            $lockout->resetAttempts($user);
        }

        // Check password expiration after successful login
        $user = auth()->user();
        if ($user && app(PasswordPolicyService::class)->isPasswordExpired($user)) {
            return $this->redirectToPasswordChange();
        }

        return $response;
    }

    private function redirectToPasswordChange(): LoginResponse
    {
        session()->put('password_expired', true);

        return new class implements LoginResponse
        {
            public function toResponse($request)
            {
                return redirect()->to(ChangePassword::getUrl());
            }
        };
    }

    /**
     * Complete a deferred SSO login by validating the MFA challenge, then
     * authenticating. The remember cookie is minted only here — never before the
     * challenge — so an incomplete challenge leaves no persistent credential.
     */
    protected function completeSsoMfaChallenge(): ?LoginResponse
    {
        $sessionUserId = session('sso_mfa.user_id');

        // The challenge form was built at mount time for the user encrypted in
        // $userUndertakingMultiFactorAuthentication. A concurrent SSO attempt in
        // another tab can overwrite the pending session user, leaving this stale
        // form validating one user's code while we log in another. Require the
        // challenged user and the pending session user to be identical.
        $challengeUserId = filled($this->userUndertakingMultiFactorAuthentication)
            ? decrypt($this->userUndertakingMultiFactorAuthentication)
            : null;

        if ($sessionUserId === null
            || $challengeUserId === null
            || ! hash_equals((string) $challengeUserId, (string) $sessionUserId)) {
            $this->clearSsoMfaChallenge();

            throw ValidationException::withMessages([
                'data.login' => 'Your sign-in session changed. Please sign in again.',
            ]);
        }

        $user = User::find($sessionUserId);

        if (! $user || ! $user->active) {
            $this->clearSsoMfaChallenge();

            throw ValidationException::withMessages([
                'data.login' => 'Your sign-in session expired. Please sign in again.',
            ]);
        }

        if ($this->isMultiFactorChallengeRateLimited($user)) {
            return null;
        }

        // Verifies the submitted code / recovery code against the pending user.
        $this->multiFactorChallengeForm->validate();

        $remember = (bool) session('sso_mfa.remember', true);
        $this->clearSsoMfaChallenge();

        Filament::auth()->login($user, $remember);
        session()->regenerate();

        if (app(PasswordPolicyService::class)->isPasswordExpired($user)) {
            return $this->redirectToPasswordChange();
        }

        return app(LoginResponse::class);
    }

    private function clearSsoMfaChallenge(): void
    {
        session()->forget(['sso_mfa.user_id', 'sso_mfa.remember']);
        $this->userUndertakingMultiFactorAuthentication = null;
    }

    /**
     * Fire the before-challenge hook for the first enabled provider so factors
     * that must send a code (e.g. email) do so when the challenge is shown.
     */
    protected function prepareSsoChallenge(User $user): void
    {
        foreach (Filament::getMultiFactorAuthenticationProviders() as $provider) {
            if (! $provider->isEnabled($user)) {
                continue;
            }

            if ($provider instanceof HasBeforeChallengeHook) {
                $provider->beforeChallenge($user);
            }

            break;
        }
    }

    /**
     * Add SSO buttons below the sign-in button.
     */
    protected function getFormActions(): array
    {
        $actions = parent::getFormActions();

        $settings = app(SettingsService::class);
        $googleEnabled = $settings->get('google_sso_enabled', false);
        $azureEnabled = $settings->get('azure_sso_enabled', false);

        if ($googleEnabled || $azureEnabled) {
            $divider = '<div class="w-full text-center">'
                .'<div class="relative flex items-center justify-center text-sm text-gray-500 dark:text-gray-400 my-2">'
                .'<span class="px-2">or</span>'
                .'</div>';

            $buttons = '';

            if ($googleEnabled) {
                $url = e(route('auth.google.redirect'));
                $buttons .= "<a href=\"{$url}\" class=\"inline-flex items-center justify-center gap-2 w-full px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-700 transition mb-2\">"
                    .'<svg class="w-5 h-5" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>'
                    .'Sign in with Google'
                    .'</a>';
            }

            if ($azureEnabled) {
                $url = e(route('auth.azure.redirect'));
                $buttons .= "<a href=\"{$url}\" class=\"inline-flex items-center justify-center gap-2 w-full px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-700 transition\">"
                    .'<svg class="w-5 h-5" viewBox="0 0 21 21" xmlns="http://www.w3.org/2000/svg"><path d="M10.5 0L0 10.5 10.5 21 21 10.5z" fill="#F35325"/><path d="M10.5 0L0 10.5h10.5V0z" fill="#F35325"/><path d="M0 10.5L10.5 21V10.5H0z" fill="#81BC06"/><path d="M10.5 0v10.5H21L10.5 0z" fill="#05A6F0"/><path d="M10.5 10.5V21H21L10.5 10.5z" fill="#FFBA08"/></svg>'
                    .'Sign in with Microsoft'
                    .'</a>';
            }

            $actions[] = new HtmlString($divider.$buttons.'</div>');
        }

        return $actions;
    }
}
