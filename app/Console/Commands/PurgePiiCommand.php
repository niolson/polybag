<?php

namespace App\Console\Commands;

use App\Enums\PackageStatus;
use App\Enums\ShipmentStatus;
use App\Models\Channel;
use App\Models\Shipment;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurgePiiCommand extends Command
{
    protected $signature = 'shipments:purge-pii
                            {--dry-run : Show what would be purged without making changes}
                            {--channel= : Only purge shipments for a specific channel ID}';

    protected $description = 'Purge recipient PII from shipped and void shipments after their retention period';

    public function handle(SettingsService $settings): int
    {
        $globalDefault = (int) $settings->get('pii_retention_days', 90);

        if ($globalDefault === 0 && ! $this->option('channel')) {
            $this->info('PII retention is set to 0 (keep forever). Skipping.');

            return self::SUCCESS;
        }

        $channelFilter = $this->option('channel');

        $channels = Channel::query()
            ->when($channelFilter, fn ($q) => $q->where('id', $channelFilter))
            ->get();

        $totalPurged = 0;

        // Process each channel
        foreach ($channels as $channel) {
            $retentionDays = $channel->pii_retention_days ?? $globalDefault;

            if ($retentionDays === 0) {
                $this->line("  {$channel->name}: retention disabled, skipping.");

                continue;
            }

            $purged = $this->purgeForChannel($channel->id, $channel->name, $retentionDays);
            $totalPurged += $purged;
        }

        // Process shipments with no channel
        if (! $channelFilter) {
            $purged = $this->purgeForChannel(null, 'No channel', $globalDefault);
            $totalPurged += $purged;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run complete. {$totalPurged} shipment(s) would be purged.");
        } else {
            $this->info("PII purge complete. {$totalPurged} shipment(s) purged.");
        }

        return self::SUCCESS;
    }

    private function purgeForChannel(?int $channelId, string $channelName, int $retentionDays): int
    {
        $query = $this->eligibleShipments($channelId, now()->subDays($retentionDays));

        /** @var array<string, int> $byStatus */
        $byStatus = (clone $query)->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $count = array_sum($byStatus);

        if ($count === 0) {
            return 0;
        }

        $breakdown = collect($byStatus)
            ->map(fn (int $statusCount, string $status): string => "{$statusCount} {$status}")
            ->implode(', ');

        $this->line("  {$channelName}: {$count} shipment(s) eligible ({$breakdown}; {$retentionDays}-day retention)");

        if ($this->option('dry-run')) {
            return $count;
        }

        $query->select('id')->chunkById(500, function (Collection $shipments): void {
            $this->purge($shipments->pluck('id'));
        });

        return $count;
    }

    /**
     * Shipments past retention whose recipient data has not been purged yet.
     *
     * The clock starts at a Shipment's last activity (`pii-retention/02`):
     *
     *  - A `shipped` Shipment counts from its latest shipped Package's
     *    `shipped_at`, or from its `updated_at` when it has no shipped Package
     *    (a historical import, or one marked shipped by hand).
     *  - A `void` Shipment counts from its `updated_at`, the nearest record of
     *    when it became void, and from any Package it shipped before that.
     *  - An `open` Shipment is never purged: it is still work.
     *
     * Only a shipped Package counts, because a Package's status projects its
     * active Label. A draft, or a Package whose Label was voided, holds no
     * Label to wait for, so it does not keep a shipped or void Shipment's PII.
     *
     * @return Builder<Shipment>
     */
    private function eligibleShipments(?int $channelId, Carbon $cutoff): Builder
    {
        return Shipment::query()
            ->when($channelId !== null, fn (Builder $q) => $q->where('channel_id', $channelId))
            ->when($channelId === null, fn (Builder $q) => $q->whereNull('channel_id'))
            ->whereNull('pii_purged_at')
            ->whereIn('status', [ShipmentStatus::Shipped, ShipmentStatus::Void])
            ->whereDoesntHave('packages', fn (Builder $q) => $q
                ->where('status', PackageStatus::Shipped)
                ->where(fn (Builder $q) => $q->whereNull('shipped_at')->orWhere('shipped_at', '>', $cutoff)))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q
                    ->where('status', ShipmentStatus::Shipped)
                    ->whereHas('packages', fn (Builder $q) => $q->where('status', PackageStatus::Shipped)))
                ->orWhere('updated_at', '<=', $cutoff));
    }

    /**
     * @param  Collection<int, int>  $shipmentIds
     */
    private function purge(Collection $shipmentIds): void
    {
        $nullFields = collect(Shipment::PII_FIELDS)
            ->mapWithKeys(fn ($field): array => [$field => null])
            ->all();

        DB::transaction(function () use ($shipmentIds, $nullFields): void {
            Shipment::whereIn('id', $shipmentIds)->update([...$nullFields, 'pii_purged_at' => now()]);

            // Null out the documents on associated packages (they carry embedded PII).
            // The customs form goes with the label: a commercial invoice names both
            // parties, their addresses, and their tax and EORI numbers, the
            // recipient tax ID among them, so keeping it after the label is gone
            // would leave the purge half-done.
            DB::table('packages')
                ->whereIn('shipment_id', $shipmentIds)
                ->where(fn ($q) => $q->whereNotNull('label_data')->orWhereNotNull('customs_form_data'))
                ->update(['label_data' => null, 'customs_form_data' => null]);
        });
    }
}
