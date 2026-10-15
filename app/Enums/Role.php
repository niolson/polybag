<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Role: string implements HasLabel
{
    case User = 'user';
    case Manager = 'manager';
    case Admin = 'admin';

    public function getLabel(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Manager => 'Manager',
            self::Admin => 'Admin',
        };
    }

    public function isAtLeast(self $role): bool
    {
        return $this->level() >= $role->level();
    }

    private function level(): int
    {
        return match ($this) {
            self::User => 1,
            self::Manager => 2,
            self::Admin => 3,
        };
    }
}
