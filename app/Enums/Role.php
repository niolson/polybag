<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Role: string implements HasLabel
{
    case Packer = 'packer';
    case Manager = 'manager';
    case Admin = 'admin';

    public function getLabel(): string
    {
        return match ($this) {
            self::Packer => 'Packer',
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
            self::Packer => 1,
            self::Manager => 2,
            self::Admin => 3,
        };
    }
}
