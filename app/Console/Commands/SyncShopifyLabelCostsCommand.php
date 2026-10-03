<?php

namespace App\Console\Commands;

use App\Services\ShopifyLabelCostRecorder;
use Illuminate\Console\Command;

class SyncShopifyLabelCostsCommand extends Command
{
    protected $signature = 'packages:sync-shopify-label-costs
        {--limit= : Maximum number of labels to check in one run}
        {--days= : How far back to look, overriding services.shopify.label_cost_check_days}';

    protected $description = 'Record Shopify Shipping label costs from the order timeline';

    public function handle(ShopifyLabelCostRecorder $recorder): int
    {
        $limit = $this->option('limit');
        $days = $this->option('days');

        $result = $recorder->sync(
            $limit === null ? null : (int) $limit,
            $days === null ? null : (int) $days,
        );

        $this->info(
            "Checked {$result['checked']} label(s): {$result['costed']} costed, {$result['failed']} failed."
        );

        // The nightly rollup only rebuilds yesterday and today, so a cost
        // landing on an older package would otherwise never reach the
        // spend reports.
        if ($result['ship_dates'] !== []) {
            $this->call('stats:aggregate', [
                '--from' => min($result['ship_dates']),
                '--to' => max($result['ship_dates']),
            ]);
        }

        return self::SUCCESS;
    }
}
