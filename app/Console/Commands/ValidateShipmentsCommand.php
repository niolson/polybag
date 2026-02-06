<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use Illuminate\Console\Command;

class ValidateShipmentsCommand extends Command
{
    protected $signature = 'shipments:validate
                            {--limit= : Maximum number of shipments to validate}
                            {--dry-run : Preview what would be validated without making changes}';

    protected $description = 'Validate addresses for pending shipments via USPS API';

    public function handle(): int
    {
        $query = Shipment::where('checked', false)
            ->where('country', 'US');

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $shipments = $query->get();

        if ($shipments->isEmpty()) {
            $this->info('No pending shipments to validate.');

            return Command::SUCCESS;
        }

        $this->info("Found {$shipments->count()} shipment(s) to validate.");

        if ($this->option('dry-run')) {
            return $this->dryRun($shipments);
        }

        $bar = $this->output->createProgressBar($shipments->count());
        $bar->start();

        $results = [
            'success' => 0,
            'errors' => 0,
            'skipped' => 0,
            'statuses' => [],
        ];

        foreach ($shipments as $shipment) {
            try {
                $shipment->validateAddress();
                $shipment->refresh();

                if (! $shipment->checked) {
                    $results['skipped']++;
                    $this->newLine();
                    $this->warn("  Skipped {$shipment->shipment_reference}: API unavailable");
                } else {
                    $results['success']++;
                    $status = $shipment->deliverability?->value ?? 'unknown';
                    $results['statuses'][$status] = ($results['statuses'][$status] ?? 0) + 1;
                }
            } catch (\Exception $e) {
                $results['errors']++;
                $this->newLine();
                $this->error("  Error validating {$shipment->shipment_reference}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Validation complete!');
        $this->newLine();

        // Results summary
        $tableData = [
            ['Validated', $results['success']],
            ['Skipped (API errors)', $results['skipped']],
            ['Errors', $results['errors']],
        ];

        foreach ($results['statuses'] as $status => $count) {
            $tableData[] = ["  - {$status}", $count];
        }

        $this->table(['Metric', 'Count'], $tableData);

        return ($results['errors'] > 0 || $results['skipped'] > 0) ? Command::FAILURE : Command::SUCCESS;
    }

    private function dryRun($shipments): int
    {
        $this->info('Dry-run mode - no changes will be made.');
        $this->newLine();

        $sample = $shipments->take(10)->map(function ($s) {
            return [
                $s->shipment_reference,
                trim("{$s->first_name} {$s->last_name}"),
                $s->address1,
                $s->city,
                $s->state,
                $s->zip,
            ];
        })->toArray();

        $this->table(
            ['Reference', 'Name', 'Address', 'City', 'State', 'ZIP'],
            $sample
        );

        if ($shipments->count() > 10) {
            $this->info('... and '.($shipments->count() - 10).' more.');
        }

        return Command::SUCCESS;
    }
}
