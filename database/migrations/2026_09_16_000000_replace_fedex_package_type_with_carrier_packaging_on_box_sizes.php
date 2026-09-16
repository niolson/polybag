<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `FedexPackageType` value to its `CarrierPackaging` twin. Frozen here
     * rather than derived from the enums: a case a later release renames or
     * removes would change what this migration does to rows it has not seen
     * yet, and a migration must do the same thing on every install it runs on.
     *
     * Every FedEx case has a twin, so nothing that was declared is nulled.
     * `YOUR_PACKAGING` and null both mean the packer's own packaging, which
     * `carrier_packaging` says with null.
     *
     * @var array<string, string>
     */
    private const FEDEX_TO_CARRIER_PACKAGING = [
        'FEDEX_ENVELOPE' => 'fedex_envelope',
        'FEDEX_PAK' => 'fedex_pak',
        'FEDEX_TUBE' => 'fedex_tube',
        'FEDEX_BOX' => 'fedex_box',
        'FEDEX_EXTRA_SMALL_BOX' => 'fedex_extra_small_box',
        'FEDEX_SMALL_BOX' => 'fedex_small_box',
        'FEDEX_MEDIUM_BOX' => 'fedex_medium_box',
        'FEDEX_LARGE_BOX' => 'fedex_large_box',
        'FEDEX_EXTRA_LARGE_BOX' => 'fedex_extra_large_box',
        'FEDEX_10KG_BOX' => 'fedex_10kg_box',
        'FEDEX_25KG_BOX' => 'fedex_25kg_box',
    ];

    /**
     * A box size has a physical form and, independently, a carrier-supplied
     * identity (ADR-0005 decisions 1 and 2). `carrier_packaging` is that
     * identity for any carrier; `fedex_package_type` could only hold FedEx's.
     * `FedexPackageType` stays as the wire enum the FedEx adapter sends — it
     * stops being something a box size stores.
     */
    public function up(): void
    {
        Schema::table('box_sizes', function (Blueprint $table): void {
            $table->string('carrier_packaging')->nullable()->after('type');
        });

        foreach (self::FEDEX_TO_CARRIER_PACKAGING as $fedexPackageType => $carrierPackaging) {
            DB::table('box_sizes')
                ->where('fedex_package_type', $fedexPackageType)
                ->update(['carrier_packaging' => $carrierPackaging]);
        }

        Schema::table('box_sizes', function (Blueprint $table): void {
            $table->dropColumn('fedex_package_type');
        });
    }

    /**
     * Refuses if any box size carries a packaging `fedex_package_type` cannot
     * hold. Nulling it would silently lose an operator's declaration; a
     * rollback after such rows exist is a decision made with the row list in
     * front of you, not a side effect of `migrate:rollback`.
     */
    public function down(): void
    {
        $unrepresentable = DB::table('box_sizes')
            ->whereNotNull('carrier_packaging')
            ->whereNotIn('carrier_packaging', array_values(self::FEDEX_TO_CARRIER_PACKAGING))
            ->orderBy('id')
            ->get(['id', 'code', 'label', 'carrier_packaging']);

        if ($unrepresentable->isNotEmpty()) {
            $rows = $unrepresentable
                ->map(fn (object $row): string => sprintf('#%d %s (%s): %s', $row->id, $row->code, $row->label, $row->carrier_packaging))
                ->implode('; ');

            throw new RuntimeException(
                "Cannot roll back: fedex_package_type cannot hold the carrier packaging on these box sizes — {$rows}. "
                .'Clear or change their carrier packaging first if the rollback is intended.'
            );
        }

        Schema::table('box_sizes', function (Blueprint $table): void {
            $table->string('fedex_package_type')->nullable()->default('YOUR_PACKAGING')->after('type');
        });

        foreach (self::FEDEX_TO_CARRIER_PACKAGING as $fedexPackageType => $carrierPackaging) {
            DB::table('box_sizes')
                ->where('carrier_packaging', $carrierPackaging)
                ->update(['fedex_package_type' => $fedexPackageType]);
        }

        DB::table('box_sizes')
            ->whereNull('carrier_packaging')
            ->update(['fedex_package_type' => 'YOUR_PACKAGING']);

        Schema::table('box_sizes', function (Blueprint $table): void {
            $table->dropColumn('carrier_packaging');
        });
    }
};
