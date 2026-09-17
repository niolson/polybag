<?php

namespace App\Console\Commands;

use App\Enums\ServiceEvidence;
use App\Models\Package;
use App\Services\ServiceInference\ServiceInferrer;
use Illuminate\Console\Command;

/**
 * Run the inference ladder over packages whose service nobody confirmed, and
 * report what each rung resolved.
 *
 * Reports by default and writes only under `--apply`, because the coverage
 * measurement ADR-0003 asks for is the evidence base for two later decisions —
 * whether label fingerprinting is ever worth building, and whether an inferred
 * service should ever be published — and both want the numbers before anything
 * is written.
 *
 * Only rung 1 is meaningful over historical packages: `PurgePiiCommand` nulls
 * `label_data` after the retention period but never touches `tracking_number`.
 * A large "no readable label" count on old packages is that policy, not a defect.
 */
class InferPackageServices extends Command
{
    protected $signature = 'app:infer-package-services
                            {--apply : Write what was inferred; otherwise only report}
                            {--source= : Limit to one postage source (e.g. sales_channel)}
                            {--limit=0 : Stop after this many packages}';

    protected $description = 'Infer the service for packages whose postage source never reported one, and report coverage';

    public function handle(ServiceInferrer $inferrer): int
    {
        $source = $this->option('source');
        $limit = (int) $this->option('limit');
        $apply = (bool) $this->option('apply');

        $query = Package::query()
            ->whereIn('service_evidence', [ServiceEvidence::Unknown->value, ServiceEvidence::Inferred->value])
            ->whereNotNull('carrier')
            ->when($source !== null, fn ($q) => $q->where('postage_source', $source))
            ->when($limit > 0, fn ($q) => $q->limit($limit));

        $byMethod = [];
        $byReason = [];
        $total = 0;
        $written = 0;
        $withdrawn = 0;

        $query->chunkById(500, function ($packages) use ($inferrer, $apply, &$byMethod, &$byReason, &$total, &$written, &$withdrawn): void {
            foreach ($packages as $package) {
                $total++;
                $inference = $inferrer->infer($package);

                if (! $inference->isResolved()) {
                    $byReason[$this->summarize($inference->reason)] = ($byReason[$this->summarize($inference->reason)] ?? 0) + 1;

                    // A contradiction is the one miss that acts on an earlier
                    // inference: the value stays reported under a ruleset that
                    // now refuses it otherwise.
                    if ($apply && $package->withdrawInferredService($inference)) {
                        $withdrawn++;
                    }

                    continue;
                }

                $byMethod[$inference->method] = ($byMethod[$inference->method] ?? 0) + 1;

                if ($apply && $package->recordInferredService($inference)) {
                    $written++;
                }
            }
        });

        if ($total === 0) {
            $this->info('No packages with an unconfirmed service.');

            return self::SUCCESS;
        }

        $resolved = array_sum($byMethod);

        $this->line(sprintf('%d package(s) with an unconfirmed service.', $total));
        $this->newLine();
        $this->line('Resolved:');

        foreach ($byMethod as $method => $count) {
            $this->line(sprintf('  %-24s %6d  %5.1f%%', $method, $count, $count / $total * 100));
        }

        if ($byMethod === []) {
            $this->line('  (none)');
        }

        $this->newLine();
        $this->line('Left unknown:');

        arsort($byReason);

        foreach ($byReason as $reason => $count) {
            $this->line(sprintf('  %-48s %6d  %5.1f%%', $reason, $count, $count / $total * 100));
        }

        $this->newLine();
        $this->line(sprintf('Coverage: %d of %d (%.1f%%)', $resolved, $total, $resolved / $total * 100));

        if ($apply) {
            $this->info("Wrote {$written} inferred service(s).");

            if ($withdrawn > 0) {
                $this->warn("Withdrew {$withdrawn} earlier inference(s) the current ruleset contradicts.");
            }
        } else {
            $this->comment('Nothing written. Re-run with --apply to record what was inferred.');
        }

        return self::SUCCESS;
    }

    /**
     * Collapse a per-package reason into something worth counting.
     *
     * Reasons name the carrier and the code they stopped on so a single package
     * can be understood; a tally of them is only useful grouped.
     */
    private function summarize(string $reason): string
    {
        return match (true) {
            // First, because these are the two figures that say whether Shopify
            // still honours a selection, and a package showing either wants a
            // person: a decode and an honoured selection naming different
            // services means a table is wrong, ours or the carrier's.
            str_contains($reason, 'but the honoured selection names') => 'decode disagrees with the honoured selection',
            str_contains($reason, 'sold the label') => 'selection not honoured: another carrier sold the label',
            str_contains($reason, 'last-mile') => 'USPS last-mile handoff, service not encoded',
            str_contains($reason, 'names no product') => 'service type code names no product',
            str_contains($reason, 'not a valid IMpb') && str_contains($reason, 'no readable label') && str_contains($reason, 'no selection requested') => 'no IMpb, no readable label, no selection',
            str_contains($reason, 'not a valid IMpb') && str_contains($reason, 'no readable label') => 'no IMpb, no readable label',
            str_contains($reason, 'no label tokens') => 'no label tokens for this carrier',
            str_contains($reason, 'no known service token') => 'label carries no known service token',
            str_contains($reason, 'more than one service') => 'label names more than one service',
            default => $reason,
        };
    }
}
