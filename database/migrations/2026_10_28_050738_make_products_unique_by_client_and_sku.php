<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One Product per SKU in a client (product-catalog-sync/01). Existing duplicates are
 * merged into the lowest id first. Duplicates are found by grouping in SQL, so they
 * match the way the unique index compares SKUs: MySQL's `utf8mb4_unicode_ci` treats
 * `abc`, `ABC` and `abc ` as one SKU, SQLite does not.
 */
return new class extends Migration
{
    /** Columns a merge never copies from a duplicate. */
    private const UNMERGED_COLUMNS = ['id', 'client_id', 'sku', 'created_at', 'updated_at'];

    /**
     * Seller declarations that default to false, so false is not a blank to fill. The
     * merged Product keeps one if any duplicate declared it: losing an alcohol or media
     * declaration is worse than keeping one.
     */
    private const DECLARATION_FLAGS = ['contains_alcohol', 'is_media'];

    public function up(): void
    {
        DB::transaction(function (): void {
            // An empty SKU is not NULL, so the index would treat two of them as a clash.
            DB::table('products')->whereRaw("TRIM(sku) = ''")->update(['sku' => null]);

            $this->mergeDuplicates();
        });

        // Add the unique index before dropping the plain one: on MySQL the plain index
        // may be the one backing the client_id foreign key.
        Schema::table('products', function (Blueprint $table): void {
            $table->unique(['client_id', 'sku']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['client_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['client_id', 'sku']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['client_id', 'sku']);
        });
    }

    private function mergeDuplicates(): void
    {
        $mergedColumns = array_values(array_diff(
            Schema::getColumnListing('products'),
            self::UNMERGED_COLUMNS,
            self::DECLARATION_FLAGS,
        ));

        // NULL SKUs are distinct Products, which SQL's GROUP BY would put together.
        $groups = DB::table('products')
            ->select('client_id', 'sku')
            ->whereNotNull('sku')
            ->groupBy('client_id', 'sku')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $rows = DB::table('products')
                ->where('client_id', $group->client_id)
                ->where('sku', $group->sku)
                ->orderBy('id')
                ->get();

            $survivor = $rows->shift();
            $duplicateIds = $rows->pluck('id')->all();

            $filled = [];

            foreach ($mergedColumns as $column) {
                if (! $this->isBlank($survivor->{$column})) {
                    continue;
                }

                $donor = $rows->first(fn (object $row): bool => ! $this->isBlank($row->{$column}));

                if ($donor !== null) {
                    $filled[$column] = $donor->{$column};
                }
            }

            foreach (self::DECLARATION_FLAGS as $flag) {
                if (! $survivor->{$flag} && $rows->contains(fn (object $row): bool => (bool) $row->{$flag})) {
                    $filled[$flag] = true;
                }
            }

            if ($filled !== []) {
                DB::table('products')->where('id', $survivor->id)->update($filled);
            }

            DB::table('shipment_items')->whereIn('product_id', $duplicateIds)->update(['product_id' => $survivor->id]);
            DB::table('package_items')->whereIn('product_id', $duplicateIds)->update(['product_id' => $survivor->id]);
            DB::table('products')->whereIn('id', $duplicateIds)->delete();

            // Values the duplicates held that differ from the merged Product are dropped.
            Log::info('Merged duplicate products into one by client and SKU', [
                'client_id' => $group->client_id,
                'sku' => $group->sku,
                'kept_product_id' => $survivor->id,
                'merged_product_ids' => $duplicateIds,
                'filled' => array_keys($filled),
            ]);
        }
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
};
