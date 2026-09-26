<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The `Amazon` carrier and its `AMAZON_BUY_SHIPPING` hook row go — ADR-0006
     * decisions 1 and 5, `carrier-catalog-reset/12`. A shipping method asks
     * Amazon Buy Shipping through an `amazon` row in its source policy.
     *
     * Each method that listed the hook row is given that row, with `none`, so
     * it goes on asking Amazon, before its pivot rows are removed.
     *
     * Anything else still naming the carrier or its row fails the migration,
     * naming the rows, rather than letting a restrict block the delete halfway
     * or a cascade drop it: a rule, a label or package normalized to it, a
     * source mapping onto it, or a scope. `15` moved the connection scopes to
     * Amazon Shipping. Offers clear to null, and accounts, aliases and
     * special-service scopes cascade, none of which carry meaning here.
     *
     * Deleted through the query builder: the model refuses to delete a system
     * carrier. Not reversible, because the reference-data sync no longer seeds
     * the row.
     */
    public function up(): void
    {
        $carrierId = DB::table('carriers')->where('name', 'Amazon')->value('id');

        if ($carrierId === null) {
            return;
        }

        $serviceIds = DB::table('carrier_services')->where('carrier_id', $carrierId)->pluck('id');

        $this->refuseWhileReferenced($carrierId, $serviceIds);

        DB::transaction(function () use ($carrierId, $serviceIds): void {
            $now = now();

            DB::table('shipping_method_postage_sources')->insertOrIgnore(
                DB::table('carrier_service_shipping_method')
                    ->whereIn('carrier_service_id', $serviceIds)
                    ->distinct()
                    ->pluck('shipping_method_id')
                    ->map(fn (int $methodId): array => [
                        'shipping_method_id' => $methodId,
                        'source_kind' => 'amazon',
                        'unlisted_services' => 'none',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->all(),
            );

            DB::table('carrier_service_shipping_method')->whereIn('carrier_service_id', $serviceIds)->delete();
            DB::table('carrier_services')->whereIn('id', $serviceIds)->delete();
            DB::table('carriers')->where('id', $carrierId)->delete();
        });
    }

    public function down(): void
    {
        //
    }

    /**
     * @param  Collection<int, int>  $serviceIds
     *
     * @throws RuntimeException
     */
    private function refuseWhileReferenced(int $carrierId, Collection $serviceIds): void
    {
        $references = [
            'shipping_rules' => DB::table('shipping_rules')
                ->where('carrier_id', $carrierId)
                ->orWhereIn('carrier_service_id', $serviceIds),
            'package_labels' => DB::table('package_labels')
                ->where('normalized_carrier_id', $carrierId)
                ->orWhereIn('carrier_service_id', $serviceIds),
            'packages' => DB::table('packages')->where('normalized_carrier_id', $carrierId),
            'source_service_mappings' => DB::table('source_service_mappings')->whereIn('carrier_service_id', $serviceIds),
            'carrier_account_scopes' => DB::table('carrier_account_scopes')->where('carrier_id', $carrierId),
        ];

        $found = collect($references)
            ->map(fn ($query): Collection => $query->orderBy('id')->pluck('id'))
            ->filter(fn (Collection $ids): bool => $ids->isNotEmpty())
            ->map(fn (Collection $ids, string $table): string => "{$table} #".$ids->implode(', #'));

        if ($found->isNotEmpty()) {
            throw new RuntimeException(
                'The Amazon carrier is going: Amazon Buy Shipping is now allowed by a shipping method\'s postage sources. '
                .'Delete or re-point what still names it, then run again: '.$found->implode('; '),
            );
        }
    }
};
