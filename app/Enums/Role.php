<?php

namespace App\Enums;

enum Role: string
{
    case User = 'user';
    case Manager = 'manager';
    case Admin = 'admin';

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
