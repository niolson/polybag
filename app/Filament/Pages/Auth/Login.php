<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\PasswordPolicyService;
use App\Services\SettingsService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
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
     * and SSO-only users.
     */
    public function authenticate(): ?LoginResponse
    {
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

        $response = parent::authenticate();

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
     * Add Google SSO button below the sign-in button.
     */
    protected function getFormActions(): array
    {
        $actions = parent::getFormActions();

        if (app(SettingsService::class)->get('google_sso_enabled', false)) {
            $url = route('auth.google.redirect');

            $actions[] = new HtmlString(
                '<div class="w-full text-center">'
                .'<div class="relative flex items-center justify-center text-sm text-gray-500 dark:text-gray-400 my-2">'
                .'<span class="px-2">or</span>'
                .'</div>'
                ."<a href=\"{$url}\" class=\"inline-flex items-center justify-center gap-2 w-full px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-gray-700 transition\">"
                .'<svg class="w-5 h-5" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>'
                .'Sign in with Google'
                .'</a>'
                .'</div>'
            );
        }

        return $actions;
    }
}
