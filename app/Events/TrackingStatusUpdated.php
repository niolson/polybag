<?php

namespace App\Events;

use App\Enums\TrackingStatus;
use App\Models\Package;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TrackingStatusUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Package $package,
        public ?TrackingStatus $previousStatus,
        public TrackingStatus $currentStatus,
    ) {}
}
