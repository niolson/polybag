<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FedEx International Economy is `INTERNATIONAL_ECONOMY`, not
     * `FEDEX_INTERNATIONAL_ECONOMY`.
     *
     * The catalog was seeded with the prefixed name by analogy with
     * `FEDEX_INTERNATIONAL_PRIORITY`, but FedEx only renamed the Priority
     * services. Economy and First kept their original codes, and FedEx's own
     * certification cases in `resources/data/carrier-test-cases/fedex/` use the
     * unprefixed one. Rating never noticed — a service code FedEx does not
     * publish simply never comes back in a rate reply, so the service looked
     * merely unavailable. Buying is where it surfaced, as a 400 with
     * `REQUESTEDSHIPMENT.SERVICETYPE.NOTSUPPORTED` and no usable message.
     *
     * Renamed in place rather than replaced: four tables point at
     * `carrier_services.id` — the shipping-method pivot, special-service
     * scoping, shipping rules, and observed-service mappings — and keeping the
     * row keeps every one of those links intact, along with any operator
     * customization of it.
     */
    public function up(): void
    {
        $fedexCarrierIds = DB::table('carriers')
            ->whereRaw('LOWER(TRIM(name)) = ?', ['fedex'])
            ->pluck('id')
            ->merge(DB::table('carrier_aliases')->where('lookup_key', 'fedex')->pluck('carrier_id'))
            ->unique();

        if ($fedexCarrierIds->isEmpty()) {
            return;
        }

        foreach ($fedexCarrierIds as $carrierId) {
            $stale = DB::table('carrier_services')
                ->where('carrier_id', $carrierId)
                ->where('service_code', 'FEDEX_INTERNATIONAL_ECONOMY');

            if (! $stale->exists()) {
                continue;
            }

            $correctExists = DB::table('carrier_services')
                ->where('carrier_id', $carrierId)
                ->where('service_code', 'INTERNATIONAL_ECONOMY')
                ->exists();

            // An install that already carries the right row would end up with two
            // rows for one service if this renamed onto it. Retire the unbuyable
            // one instead — deleting it would cascade through those same four
            // tables and take an operator's scoping with it.
            $stale->update($correctExists
                ? ['active' => false, 'updated_at' => now()]
                : ['service_code' => 'INTERNATIONAL_ECONOMY', 'updated_at' => now()]);
        }
    }

    /**
     * Deliberately irreversible. Restoring the prefixed code would restore a
     * service that cannot be bought.
     */
    public function down(): void {}
};
