<?php

namespace App\Services;

use App\Models\Client;
use RuntimeException;

/**
 * Bound scoped: the default Client is read once per request or queue job,
 * not once per imported row. Saving or deleting any Client forgets it.
 */
class ClientContext
{
    private ?Client $default = null;

    public function default(): Client
    {
        return $this->default ??= Client::where('is_default', true)->first()
            ?? throw new RuntimeException('No default client is set up. Create a client under Admin → Clients.');
    }

    public function id(): int
    {
        return $this->default()->id;
    }

    public function forget(): void
    {
        $this->default = null;
    }
}
