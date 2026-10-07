<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Countries whose addresses carry USPS delivery-point data. A Google result
     * for one of these can be a real DPV confirmation, and nothing stored on
     * the row tells it apart from a reference-data match.
     */
    private const USPS_SERVICE_AREA = ['US', 'PR', 'VI', 'GU', 'AS', 'MP', 'MH', 'FM', 'PW'];

    /**
     * Deliverability says what the evidence supports
     * (`address-validation-routing/03`): `maybe` is renamed `partial`, and a
     * `yes` outside the USPS service area, which only Google's verdict path
     * could have produced, becomes `verified`. Existing `no` rows cannot be
     * split and are left alone.
     */
    public function up(): void
    {
        DB::table('shipments')
            ->where('deliverability', 'maybe')
            ->update(['deliverability' => 'partial']);

        DB::table('shipments')
            ->where('deliverability', 'yes')
            ->whereNotIn('country', self::USPS_SERVICE_AREA)
            ->update(['deliverability' => 'verified']);
    }

    /**
     * `unverified` had no equivalent before; it is what every validator's
     * inconclusive reading used to leave behind, which was `no`.
     */
    public function down(): void
    {
        DB::table('shipments')->where('deliverability', 'partial')->update(['deliverability' => 'maybe']);
        DB::table('shipments')->where('deliverability', 'verified')->update(['deliverability' => 'yes']);
        DB::table('shipments')->where('deliverability', 'unverified')->update(['deliverability' => 'no']);
    }
};
