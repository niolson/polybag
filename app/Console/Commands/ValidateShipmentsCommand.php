<?php

namespace App\Console\Commands;

use App\Enums\AddressValidationOutcome;
use App\Enums\ValidationTrigger;
use App\Models\AddressValidationAnswer;
use App\Models\Shipment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ValidateShipmentsCommand extends Command
{
    protected $signature = 'shipments:validate
                            {--limit= : Maximum number of shipments to validate}
                            {--dry-run : Preview what would be validated without making changes}';

    protected $description = 'Validate addresses for pending shipments no validator has answered for yet';

    /**
     * Scheduled attempts allowed per Shipment. An address change or a new
     * shipping method earns another attempt, so a source that keeps changing
     * an address (a duplicated record ID, say) would otherwise re-validate it
     * on every import. Validating by hand ignores this.
     */
    public const MAX_SCHEDULED_ATTEMPTS = 5;

    public function handle(): int
    {
        // A Shipment every validator has already answered for is left alone
        // until someone validates it by hand or changes its address or method.
        $query = Shipment::where('checked', false)
            ->whereNull('validation_attempted_at')
            ->where('validation_attempts', '<', self::MAX_SCHEDULED_ATTEMPTS)
            ->where('country', 'US');

        $this->warnAboutCappedShipments();

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

        // Answer timestamps are stored to the second.
        $startedAt = now()->startOfSecond();
        $bar = $this->output->createProgressBar($shipments->count());
        $bar->start();

        $results = [
            'success' => 0,
            'unsettled' => 0,
            'errors' => 0,
            'skipped' => 0,
            'statuses' => [],
            'sources' => [],
        ];

        foreach ($shipments as $shipment) {
            try {
                $outcome = $shipment->validateAddress(ValidationTrigger::Scheduled);

                if ($outcome->answered()) {
                    $this->countScheduledAttempt($shipment);
                }

                $shipment->refresh();

                switch ($outcome) {
                    case AddressValidationOutcome::Settled:
                        $results['success']++;
                        $status = $shipment->deliverability->value;
                        $results['statuses'][$status] = ($results['statuses'][$status] ?? 0) + 1;
                        $source = $shipment->validation_source?->getLabel() ?? 'Unknown';
                        $results['sources'][$source] = ($results['sources'][$source] ?? 0) + 1;
                        break;

                    case AddressValidationOutcome::Inconclusive:
                        $results['unsettled']++;
                        break;

                    case AddressValidationOutcome::Unavailable:
                        $results['skipped']++;
                        $this->newLine();
                        $this->warn("  Skipped {$shipment->shipment_reference}: validators unavailable");
                        break;
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
            ['Attempted, still unsettled', $results['unsettled']],
            ['Skipped (validators unavailable)', $results['skipped']],
            ['Errors', $results['errors']],
        ];

        foreach ($results['statuses'] as $status => $count) {
            $tableData[] = ["  - {$status}", $count];
        }

        foreach ($results['sources'] as $source => $count) {
            $tableData[] = ["  - settled by {$source}", $count];
        }

        $tableData[] = ['Paid validator requests', $this->paidRequests($shipments->modelKeys(), $startedAt)];

        $this->table(['Metric', 'Count'], $tableData);

        // An address no validator could settle is an answer, not a failure;
        // only validators that couldn't run, or exceptions, fail the command.
        return ($results['errors'] > 0 || $results['skipped'] > 0) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Answers from paid validators in this run. A validator that couldn't run
     * writes no answer, so an outage or rate limit isn't counted.
     *
     * @param  list<int>  $shipmentIds
     */
    private function paidRequests(array $shipmentIds, \DateTimeInterface $startedAt): int
    {
        return AddressValidationAnswer::query()
            ->whereIn('shipment_id', $shipmentIds)
            ->where('trigger', ValidationTrigger::Scheduled)
            ->where('paid', true)
            ->where('created_at', '>=', $startedAt)
            ->count();
    }

    private function countScheduledAttempt(Shipment $shipment): void
    {
        $shipment->increment('validation_attempts');

        if ($shipment->validation_attempts === self::MAX_SCHEDULED_ATTEMPTS) {
            Log::warning('Shipment reached the scheduled address validation limit; it will only be validated by hand from now on', [
                'shipment_id' => $shipment->id,
                'attempts' => $shipment->validation_attempts,
            ]);
        }
    }

    /**
     * Shipments that would be due but have used up their scheduled attempts:
     * usually a source changing their address on every import.
     */
    private function warnAboutCappedShipments(): void
    {
        $capped = Shipment::where('checked', false)
            ->whereNull('validation_attempted_at')
            ->where('validation_attempts', '>=', self::MAX_SCHEDULED_ATTEMPTS)
            ->where('country', 'US')
            ->count();

        if ($capped > 0) {
            $this->warn("{$capped} shipment(s) reached the limit of ".self::MAX_SCHEDULED_ATTEMPTS.' scheduled validations and must be validated by hand.');
        }
    }

    private function dryRun($shipments): int
    {
        $this->info('Dry-run mode - no changes will be made.');
        $this->newLine();

        $sample = $shipments->take(10)->map(function ($s): array {
            return [
                $s->shipment_reference,
                trim("{$s->first_name} {$s->last_name}"),
                $s->address1,
                $s->city,
                $s->state_or_province,
                $s->postal_code,
            ];
        })->toArray();

        $this->table(
            ['Reference', 'Name', 'Address', 'City', 'State/Province', 'Postal Code'],
            $sample
        );

        if ($shipments->count() > 10) {
            $this->info('... and '.($shipments->count() - 10).' more.');
        }

        return Command::SUCCESS;
    }
}
