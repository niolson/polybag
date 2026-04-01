<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Shipment;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgePiiCommand extends Command
{
    protected $signature = 'shipments:purge-pii
                            {--dry-run : Show what would be purged without making changes}
                            {--channel= : Only purge shipments for a specific channel ID}';

    protected $description = 'Purge recipient PII from shipped shipments after their retention period';

    private const PII_FIELDS = [
        'first_name',
        'last_name',
        'company',
        'address1',
        'address2',
        'city',
        'state_or_province',
        'phone',
        'phone_e164',
        'phone_extension',
        'email',
        'validated_company',
        'validated_address1',
        'validated_address2',
        'validated_city',
        'validated_state_or_province',
    ];

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
        $cutoff = now()->subDays($retentionDays);

        // Find shipments where all packages are shipped and the most recent
        // shipped_at is older than the cutoff, and PII hasn't been purged yet
        $query = Shipment::query()
            ->when($channelId !== null, fn ($q) => $q->where('channel_id', $channelId))
            ->when($channelId === null, fn ($q) => $q->whereNull('channel_id'))
            ->whereNotNull('first_name') // Already purged if null
            ->whereHas('packages')
            ->whereDoesntHave('packages', fn ($q) => $q->where('status', '!=', 'shipped'))
            ->whereDoesntHave('packages', fn ($q) => $q->where('shipped_at', '>', $cutoff)->orWhereNull('shipped_at'));

        $count = $query->count();

        if ($count === 0) {
            return 0;
        }

        $this->line("  {$channelName}: {$count} shipment(s) eligible ({$retentionDays}-day retention)");

        if ($this->option('dry-run')) {
            return $count;
        }

        // Null out PII fields on shipments
        $shipmentIds = $query->pluck('id');

        $nullFields = collect(self::PII_FIELDS)
            ->mapWithKeys(fn ($field) => [$field => null])
            ->all();

        Shipment::whereIn('id', $shipmentIds)->update($nullFields);

        // Null out label_data on associated packages (contains embedded PII)
        DB::table('packages')
            ->whereIn('shipment_id', $shipmentIds)
            ->whereNotNull('label_data')
            ->update(['label_data' => null]);

        return $count;
    }
}
