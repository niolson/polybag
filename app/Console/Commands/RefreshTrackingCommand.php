<?php

namespace App\Console\Commands;

use App\Enums\PackageStatus;
use App\Enums\TrackingStatus;
use App\Jobs\RefreshPackageTrackingJob;
use App\Models\Package;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RefreshTrackingCommand extends Command
{
    protected $signature = 'packages:refresh-tracking
        {--limit=100 : Maximum number of packages to queue per run}';

    protected $description = 'Queue tracking refresh jobs for shipped packages that are overdue for a status check';

    /**
     * Polling tiers: packages shipped within each window are checked at the
     * given interval. Packages older than the oldest tier are abandoned and
     * marked as exceptions to prevent runaway API usage.
     *
     * @var array<int, array{days: int, hours: int}>
     */
    private const TIERS = [
        ['days' => 3,  'hours' => 4],
        ['days' => 14, 'hours' => 24],
        ['days' => 45, 'hours' => 72],
    ];

    private const ABANDON_AFTER_DAYS = 45;

    public function handle(): int
    {
        $this->abandonStale();

        $limit = (int) $this->option('limit');
        $queued = 0;

        foreach (self::TIERS as $tier) {
            $ids = Package::query()
                ->where('status', PackageStatus::Shipped)
                ->whereNotNull('tracking_number')
                ->whereNotNull('carrier')
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('tracking_status')
                        ->orWhereNotIn('tracking_status', [
                            TrackingStatus::Delivered->value,
                            TrackingStatus::Returned->value,
                        ]);
                })
                ->where('shipped_at', '>=', now()->subDays(self::ABANDON_AFTER_DAYS))
                ->where('shipped_at', '>=', now()->subDays($tier['days']))
                ->where(function (Builder $query) use ($tier): void {
                    $query
                        ->whereNull('tracking_checked_at')
                        ->orWhere('tracking_checked_at', '<=', now()->subHours($tier['hours']));
                })
                ->orderBy('tracking_checked_at')
                ->limit($limit - $queued)
                ->pluck('id');

            $ids->each(fn (int $id) => RefreshPackageTrackingJob::dispatch($id));
            $queued += $ids->count();

            if ($queued >= $limit) {
                break;
            }
        }

        $this->info("Queued {$queued} tracking refresh jobs.");

        return self::SUCCESS;
    }

    /**
     * Mark shipped packages older than the abandon threshold as exceptions
     * so they stop being queried and surface in the exceptions dashboard.
     */
    private function abandonStale(): void
    {
        $count = Package::query()
            ->where('status', PackageStatus::Shipped)
            ->whereNotNull('tracking_number')
            ->where('shipped_at', '<', now()->subDays(self::ABANDON_AFTER_DAYS))
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('tracking_status')
                    ->orWhereNotIn('tracking_status', [
                        TrackingStatus::Delivered->value,
                        TrackingStatus::Returned->value,
                        TrackingStatus::Exception->value,
                    ]);
            })
            ->update(['tracking_status' => TrackingStatus::Exception]);

        if ($count > 0) {
            $this->warn("Marked {$count} stale package(s) as Exception (shipped > ".self::ABANDON_AFTER_DAYS.' days ago, never resolved).');
        }
    }
}
