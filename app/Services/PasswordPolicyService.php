<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\Rules\Password;

class PasswordPolicyService
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * Build a Password validation rule based on configured policy.
     */
    public function rule(): Password
    {
        $rule = Password::min((int) $this->settings->get('password_min_length', 8));

        if ($this->settings->get('password_require_mixed_case', true)) {
            $rule->mixedCase();
        }

        if ($this->settings->get('password_require_numbers', true)) {
            $rule->numbers();
        }

        if ($this->settings->get('password_require_symbols', false)) {
            $rule->symbols();
        }

        return $rule;
    }

    /**
     * Check if a user's password has expired.
     */
    public function isPasswordExpired(User $user): bool
    {
        $expirationDays = (int) $this->settings->get('password_expiration_days', 0);

        if ($expirationDays === 0) {
            return false;
        }

        if (! $user->hasLocalPassword()) {
            return false;
        }

        if (! $user->password_changed_at) {
            // Never set — treat as expired to force initial password change
            return true;
        }

        return $user->password_changed_at->addDays($expirationDays)->isPast();
    }
}
