<?php

namespace App\Console\Commands;

use App\Enums\PostageSource;
use App\Enums\PostageSourceKind;
use App\Enums\ServiceEvidence;
use App\Models\PackageLabel;
use App\Models\SourceServiceMapping;
use App\Services\CarrierNormalizer;
use App\Services\Carriers\AmazonBuyShippingAdapter;
use App\Services\CatalogServiceResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Name the catalog service of every Label bought before Labels recorded one —
 * issue `postage-source-split/15`.
 *
 * A purchase since then records it from the rate, and an inference from the
 * ruleset; this reaches the Labels that predate both, so the Packages list and
 * its Service filter read alike across last month and this one.
 *
 * Idempotent: it touches only Labels with no catalog service yet, and a write
 * is conditioned on that, so a Label written meanwhile is left alone. A Label it
 * cannot resolve stays null, which is a valid answer, and is counted.
 *
 * Reports by default and writes only under `--apply`, as
 * `app:infer-package-services` does.
 */
class BackfillLabelCatalogServices extends Command
{
    protected $signature = 'app:backfill-label-catalog-services
                            {--apply : Write what was resolved; otherwise only report}';

    protected $description = 'Record the catalog service of Labels bought before Labels recorded one';

    public function __construct(
        private readonly CatalogServiceResolver $resolver,
        private readonly CarrierNormalizer $normalizer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        /** @var array<string, array{resolved: int, unresolved: int}> $bySource */
        $bySource = [];
        $written = 0;

        PackageLabel::query()
            ->whereNull('carrier_service_id')
            ->whereNotNull('service')
            ->with(['package', 'postageDataSource'])
            ->chunkById(500, function (Collection $labels) use ($apply, &$bySource, &$written): void {
                foreach ($labels as $label) {
                    $source = $this->sourceOf($label);
                    $bySource[$source] ??= ['resolved' => 0, 'unresolved' => 0];

                    $carrierServiceId = $this->resolve($label);

                    if ($carrierServiceId === null) {
                        $bySource[$source]['unresolved']++;

                        continue;
                    }

                    $bySource[$source]['resolved']++;

                    if ($apply) {
                        $written += PackageLabel::query()
                            ->whereKey($label->id)
                            ->whereNull('carrier_service_id')
                            ->update(['carrier_service_id' => $carrierServiceId]);
                    }
                }
            });

        if ($bySource === []) {
            $this->info('Every Label with a service already names its catalog service.');

            return self::SUCCESS;
        }

        ksort($bySource);

        $this->table(
            ['Source', 'Resolved', 'Unresolved'],
            collect($bySource)
                ->map(fn (array $counts, string $source): array => [$source, $counts['resolved'], $counts['unresolved']])
                ->values()
                ->all(),
        );

        if ($apply) {
            $this->info("Recorded the catalog service on {$written} Label(s).");
        } else {
            $this->comment('Nothing written. Re-run with --apply to record what was resolved.');
        }

        return self::SUCCESS;
    }

    /**
     * An inferred service resolves through the ruleset, as a new inference
     * does. A reported one resolves through Amazon's own mapping where the
     * Label still carries Amazon's identity — only the active Label does, a
     * void strips it — and otherwise by name.
     *
     * The carrier is the one resolved at purchase where the Label has it. A
     * Label older than `normalized_carrier_id` has only the source's name for
     * its carrier, resolved here for the lookup and not written: naming a
     * Label's carrier is issue `03`'s, and it chose not to backfill.
     */
    private function resolve(PackageLabel $label): ?int
    {
        $carrierId = $label->normalized_carrier_id ?? $this->normalizer->resolve($label->carrier)?->id;

        if ($label->service_evidence === ServiceEvidence::Inferred) {
            return $this->resolver->forInferredService($carrierId, $label->service);
        }

        if ($label->service_evidence !== ServiceEvidence::Confirmed) {
            return null;
        }

        return $this->amazonMapping($label)
            ?? $this->resolver->forReportedService($carrierId, $label->service);
    }

    private function amazonMapping(PackageLabel $label): ?int
    {
        if ($label->isVoided() || $label->postage_source !== PostageSource::PostageDataSource) {
            return null;
        }

        $metadata = is_array($label->package?->metadata) ? $label->package->metadata : [];
        $carrierId = $metadata[AmazonBuyShippingAdapter::CARRIER_ID_KEY] ?? null;
        $serviceId = $metadata[AmazonBuyShippingAdapter::SERVICE_ID_KEY] ?? null;

        if (blank($carrierId) || blank($serviceId)) {
            return null;
        }

        return SourceServiceMapping::query()
            ->forIdentity(PostageSourceKind::Amazon, (string) $carrierId, (string) $serviceId)
            ->value('carrier_service_id');
    }

    /**
     * What the report groups by: the postage source, with the carrier of
     * record, since each source names services in its own way.
     */
    private function sourceOf(PackageLabel $label): string
    {
        $source = match ($label->postage_source) {
            PostageSource::CarrierAccount => 'direct',
            PostageSource::PostageDataSource => $label->postageDataSource->name ?? 'connection',
            default => 'unknown source',
        };

        return "{$source} / ".($label->carrier ?? 'no carrier');
    }
}
