<?php

namespace App\Console\Commands;

use App\Models\Package;
use Illuminate\Console\Command;

class BackfillWeightMismatch extends Command
{
    protected $signature = 'packages:backfill-weight-mismatch';

    protected $description = 'Backfill the weight_mismatch flag on shipped packages';

    public function handle(): int
    {
        $query = Package::query()
            ->where('shipped', true)
            ->whereNotNull('weight')
            ->where('weight', '>', 0);

        $total = $query->count();
        $updated = 0;

        $this->info("Processing {$total} shipped packages with weight...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->with('packageItems.product')
            ->chunkById(500, function ($packages) use (&$updated, $bar) {
                $mismatchIds = [];

                foreach ($packages as $package) {
                    if ($package->computeWeightMismatch()) {
                        $mismatchIds[] = $package->id;
                    }
                    $bar->advance();
                }

                if (! empty($mismatchIds)) {
                    Package::whereIn('id', $mismatchIds)->update(['weight_mismatch' => true]);
                    $updated += count($mismatchIds);
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info("Done. Flagged {$updated} of {$total} packages as weight mismatches.");

        return self::SUCCESS;
    }
}
