<?php

namespace Tests\Fixtures\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A queued job with no `failed()` of its own that dies of a fatal error.
 */
class ExhaustsMemoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(): void
    {
        ExhaustsMemory::now();
    }
}
