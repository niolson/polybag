<?php

namespace App\Jobs;

use App\Models\Package;
use App\Services\TrackingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshPackageTrackingJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public function __construct(
        public int $packageId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(TrackingService $trackingService): void
    {
        $package = Package::find($this->packageId);

        if (! $package) {
            return;
        }

        $trackingService->refreshPackage($package);
    }
}
