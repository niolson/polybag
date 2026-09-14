<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The columns copied from a shipped package onto its label row, keyed by
     * the package column. Frozen here rather than imported from
     * `PackageLabel::PROJECTED_COLUMNS`: a column a later release adds to that
     * constant would make this migration select a column that does not yet
     * exist on a fresh install.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        'tracking_number' => 'tracking_number',
        'postage_source' => 'postage_source',
        'carrier_account_id' => 'carrier_account_id',
        'postage_data_source_id' => 'postage_data_source_id',
        'carrier' => 'carrier',
        'normalized_carrier_id' => 'normalized_carrier_id',
        'service' => 'service',
        'requested_service' => 'requested_service',
        'service_evidence' => 'service_evidence',
        'service_inference_method' => 'service_inference_method',
        'service_ruleset_version' => 'service_ruleset_version',
        'cost' => 'cost',
        'label_format' => 'label_format',
        'label_dpi' => 'label_dpi',
        'label_orientation' => 'label_orientation',
        'ship_date' => 'ship_date',
        'shipped_at' => 'purchased_at',
        'shipped_by_user_id' => 'purchased_by_user_id',
        'label_printed_at' => 'last_printed_at',
    ];

    /**
     * One label row for every package that is shipped right now, from its own
     * columns. Nothing for unshipped packages, and nothing for labels voided
     * before this table existed — the audit rows that should name them hold
     * nulls, and no state is invented to say so (ADR-0004 decision 5).
     *
     * Skips any package that already has a label row, so a re-run after a
     * partial failure adds nothing twice and the unique index never fires.
     */
    public function up(): void
    {
        $now = now();

        DB::table('packages')
            ->where('status', 'shipped')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('package_labels')
                    ->whereColumn('package_labels.package_id', 'packages.id');
            })
            ->select(array_merge(['id', 'metadata'], array_keys(self::COLUMNS)))
            ->orderBy('id')
            ->chunkById(500, function ($packages) use ($now): void {
                $rows = [];

                foreach ($packages as $package) {
                    $row = ['package_id' => $package->id];

                    foreach (self::COLUMNS as $packageColumn => $labelColumn) {
                        $row[$labelColumn] = $package->{$packageColumn};
                    }

                    $row['source_label_reference'] = $this->sourceLabelReferenceFrom($package->metadata);
                    $row['created_at'] = $now;
                    $row['updated_at'] = $now;

                    $rows[] = $row;
                }

                DB::table('package_labels')->insert($rows);
            });
    }

    /**
     * The same keys the Shopify and Amazon adapters strip on void, read from
     * the metadata they are still present in for a live label.
     */
    private function sourceLabelReferenceFrom(?string $metadata): ?string
    {
        if (blank($metadata)) {
            return null;
        }

        $decoded = json_decode($metadata, true);

        if (! is_array($decoded)) {
            return null;
        }

        foreach (['shopify_shipping_label_id', 'amazon_shipment_id'] as $key) {
            if (filled($decoded[$key] ?? null)) {
                return (string) $decoded[$key];
            }
        }

        return null;
    }

    public function down(): void
    {
        // The table's own migration drops every row with it.
    }
};
