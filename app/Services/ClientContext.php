<?php

namespace App\Services;

use App\Models\Client;
use RuntimeException;

class ClientContext
{
    public function default(): Client
    {
        $client = Client::where('is_default', true)->first();

        if (! $client) {
            throw new RuntimeException('No default client is set up. Create a client under Admin → Clients.');
        }

        return $client;
    }

    public function id(): int
    {
        return $this->default()->id;
    }
}
