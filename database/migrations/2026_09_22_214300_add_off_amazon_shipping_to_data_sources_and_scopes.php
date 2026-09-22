<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ONE_TARGET_CONSTRAINT = 'carrier_account_scopes_one_target';

    /** `AmazonBuyShippingAdapter::SOURCE_NAME`, frozen as of this migration. */
    private const AMAZON_CARRIER = 'Amazon';

    /**
     * Let an Amazon connection sell Amazon Shipping for orders from other
     * channels, chosen by a `carrier_account_scopes` row that targets the
     * connection instead of a carrier account (ADR-0002, 2026-09-22 amendment).
     *
     * The opt-in and the result of checking the account are columns rather
     * than `settings` keys, so the result can be queried and cast.
     */
    public function up(): void
    {
        Schema::table('data_sources', function (Blueprint $table) {
            $table->boolean('offers_off_amazon_shipping')->default(false)->after('import_enabled');
            $table->string('off_amazon_shipping_status')->nullable()->after('offers_off_amazon_shipping');
            $table->timestamp('off_amazon_shipping_checked_at')->nullable()->after('off_amazon_shipping_status');
        });

        $this->deleteCarrierAccountScopesOnAmazon();

        Schema::table('carrier_account_scopes', function (Blueprint $table) {
            $table->unsignedBigInteger('carrier_account_id')->nullable()->change();
            $table->foreignId('data_source_id')->nullable()->after('carrier_account_id')->constrained()->cascadeOnDelete();
        });

        // SQLite cannot add a check constraint to an existing table, so there
        // the scope's `saving` hook is the only enforcement.
        if ($this->supportsCheckConstraints()) {
            DB::statement('ALTER TABLE carrier_account_scopes ADD CONSTRAINT '.self::ONE_TARGET_CONSTRAINT
                .' CHECK ((carrier_account_id IS NULL) <> (data_source_id IS NULL))');
        }
    }

    public function down(): void
    {
        if ($this->supportsCheckConstraints()) {
            DB::statement('ALTER TABLE carrier_account_scopes DROP CHECK '.self::ONE_TARGET_CONSTRAINT);
        }

        DB::table('carrier_account_scopes')->whereNull('carrier_account_id')->delete();

        Schema::table('carrier_account_scopes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('data_source_id');
            $table->unsignedBigInteger('carrier_account_id')->nullable(false)->change();
        });

        Schema::table('data_sources', function (Blueprint $table) {
            $table->dropColumn(['offers_off_amazon_shipping', 'off_amazon_shipping_status', 'off_amazon_shipping_checked_at']);
        });
    }

    /**
     * A carrier account scoped to the `Amazon` row claims Amazon postage is
     * bought directly, when it is bought through an Amazon connection. None of
     * these rows ever sold a label — Amazon has no direct adapter, so the
     * resolver reported each one as a conflict — but each holds a slot a
     * connection scope now needs. The scope model refuses new ones.
     */
    private function deleteCarrierAccountScopesOnAmazon(): void
    {
        $amazonCarrierId = DB::table('carriers')->where('name', self::AMAZON_CARRIER)->value('id');

        if ($amazonCarrierId === null) {
            return;
        }

        $ids = DB::table('carrier_account_scopes')->where('carrier_id', $amazonCarrierId)->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        logger()->warning('Deleted carrier account scopes on the Amazon postage-source row', [
            'carrier_account_scope_ids' => $ids,
        ]);

        DB::table('carrier_account_scopes')->whereIn('id', $ids)->delete();
    }

    private function supportsCheckConstraints(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
