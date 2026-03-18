<?php

namespace App\Console\Commands;

use App\Listeners\InvalidateDashboardCache;
use App\Models\DailyShippingStat;
use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AggregateShippingStats extends Command
{
    protected $signature = 'stats:aggregate
        {--from= : Start date (Y-m-d)}
        {--to= : End date (Y-m-d)}
        {--today : Rebuild today only}';

    protected $description = 'Aggregate daily shipping stats from the packages table';

    public function handle(): int
    {
        $start = microtime(true);

        [$from, $to] = $this->resolveDateRange();

        $this->info("Aggregating stats from {$from->toDateString()} to {$to->toDateString()}...");

        // Delete existing rows for the date range, then re-aggregate
        DailyShippingStat::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->delete();

        $inserted = $this->aggregateRange($from, $to);

        InvalidateDashboardCache::invalidateAll();

        // Refresh histograms on the full nightly rebuild (not --today)
        if (! $this->option('today') && DB::getDriverName() === 'mysql' && ! str_contains(DB::getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), 'MariaDB')) {
            DB::statement('ANALYZE TABLE packages UPDATE HISTOGRAM ON status');
            DB::statement('ANALYZE TABLE shipments UPDATE HISTOGRAM ON status');
            $this->info('Updated column histograms.');
        }

        $elapsed = round(microtime(true) - $start, 2);
        $this->info("Done. {$inserted} rows created in {$elapsed}s.");

        return self::SUCCESS;
    }

    /**
     * Determine the date range to aggregate.
     *
     * @return array{Carbon, Carbon}
     */
    private function resolveDateRange(): array
    {
        $localToday = Carbon::today(Location::timezone());

        if ($this->option('today')) {
            return [$localToday, $localToday];
        }

        if ($this->option('from') || $this->option('to')) {
            $from = $this->option('from')
                ? Carbon::parse($this->option('from'))
                : Carbon::parse('2020-01-01');

            $to = $this->option('to')
                ? Carbon::parse($this->option('to'))
                : $localToday;

            return [$from, $to];
        }

        // Default: yesterday + today
        return [$localToday->copy()->subDay(), $localToday];
    }

    /**
     * Run the INSERT...SELECT aggregation.
     */
    private function aggregateRange(Carbon $from, Carbon $to): int
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return $this->aggregateViaPHP($from, $to);
        }

        // MySQL: efficient INSERT...SELECT
        return DB::affectingStatement('
            INSERT INTO daily_shipping_stats
                (date, carrier, service, channel_id, shipping_method_id, location_id, package_count, total_cost, total_weight, created_at, updated_at)
            SELECT
                p.ship_date,
                p.carrier,
                p.service,
                s.channel_id,
                s.shipping_method_id,
                p.location_id,
                COUNT(*),
                COALESCE(SUM(p.cost), 0),
                COALESCE(SUM(p.weight), 0),
                NOW(),
                NOW()
            FROM packages p
            JOIN shipments s ON p.shipment_id = s.id
            WHERE p.status = "shipped"
              AND p.ship_date BETWEEN ? AND ?
            GROUP BY p.ship_date, p.carrier, p.service, s.channel_id, s.shipping_method_id, p.location_id
        ', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * SQLite fallback: aggregate via PHP (for tests).
     */
    private function aggregateViaPHP(Carbon $from, Carbon $to): int
    {
        $rows = DB::table('packages as p')
            ->join('shipments as s', 'p.shipment_id', '=', 's.id')
            ->where('p.status', 'shipped')
            ->whereBetween('p.ship_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('p.ship_date as date, p.carrier, p.service, s.channel_id, s.shipping_method_id, p.location_id')
            ->selectRaw('COUNT(*) as package_count, COALESCE(SUM(p.cost), 0) as total_cost, COALESCE(SUM(p.weight), 0) as total_weight')
            ->groupBy('p.ship_date', 'p.carrier', 'p.service', 's.channel_id', 's.shipping_method_id', 'p.location_id')
            ->get();

        $now = now();
        $inserts = $rows->map(fn ($row) => [
            'date' => $row->date,
            'carrier' => $row->carrier,
            'service' => $row->service,
            'channel_id' => $row->channel_id,
            'shipping_method_id' => $row->shipping_method_id,
            'location_id' => $row->location_id,
            'package_count' => $row->package_count,
            'total_cost' => $row->total_cost,
            'total_weight' => $row->total_weight,
            'created_at' => $now,
            'updated_at' => $now,
        ])->toArray();

        if (! empty($inserts)) {
            DailyShippingStat::insert($inserts);
        }

        return count($inserts);
    }
}
