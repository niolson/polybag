<?php

namespace App\Events;

use App\Models\Manifest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ManifestCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Manifest $manifest,
        public int $packageCount,
    ) {}
}
