<?php

namespace App\Exceptions;

use LogicException;

/**
 * A system carrier's name is its key, so it cannot be renamed or deleted —
 * ADR-0006 decision 1, as amended 2026-09-25.
 */
class SystemCarrierLockedException extends LogicException
{
    public static function rename(string $name): self
    {
        return new self("{$name} is a system carrier, so its name cannot change. Set a display name instead.");
    }

    public static function delete(string $name): self
    {
        return new self("{$name} is a system carrier and cannot be deleted. Deactivate it instead.");
    }
}
