<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ImportCompleted
{
    use Dispatchable;

    public function __construct(
        public array $stats,
        public string $sourceName,
    ) {}
}
