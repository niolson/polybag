<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember that a Label was bought on an account, after the account is gone.
     *
     * A direct Label is voided and tracked on the account that bought it
     * (`project-review/06`). `carrier_account_id` is null-on-delete, so once
     * that account is deleted the id alone cannot tell "bought on an account
     * that no longer exists" from "bought before accounts were recorded", and
     * only the second may fall back to scope resolution. The digest of the
     * account's billing identity (`CarrierAccount::fingerprint()`) survives
     * the delete and says which it was, the way `shipping_offers` already
     * does for recovery.
     *
     * Existing labels whose account still exists are backfilled from the
     * account as it is now. This repeats `fingerprint()` rather than loading
     * the model, so the migration does not change when the model does; a
     * backfilled digest that stops matching is only logged, never refused.
     */
    public function up(): void
    {
        Schema::table('package_labels', function (Blueprint $table) {
            $table->string('carrier_account_fingerprint', 64)->nullable()->after('carrier_account_id');
        });

        DB::table('carrier_accounts')
            ->whereIn('id', DB::table('package_labels')->whereNotNull('carrier_account_id')->select('carrier_account_id'))
            ->orderBy('id')
            ->each(function (object $account): void {
                DB::table('package_labels')
                    ->where('carrier_account_id', $account->id)
                    ->whereNull('carrier_account_fingerprint')
                    ->update(['carrier_account_fingerprint' => $this->fingerprint($account)]);
            });
    }

    public function down(): void
    {
        Schema::table('package_labels', function (Blueprint $table) {
            $table->dropColumn('carrier_account_fingerprint');
        });
    }

    private function fingerprint(object $account): string
    {
        $credentials = json_decode((string) $account->credentials, true);

        return hash('sha256', json_encode([
            'carrier_id' => (int) $account->carrier_id,
            'credentials' => $this->sortedRecursively(is_array($credentials) ? $credentials : []),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function sortedRecursively(array $values): array
    {
        ksort($values);

        return array_map(
            fn (mixed $value): mixed => is_array($value) ? $this->sortedRecursively($value) : $value,
            $values,
        );
    }
};
