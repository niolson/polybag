<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('packages', 'location_id')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->foreignId('location_id')->nullable()->after('shipment_id')->constrained()->nullOnDelete();
            });
        }

        // Backfill existing packages with the default location
        $defaultLocationId = DB::table('locations')->where('is_default', true)->value('id');

        if ($defaultLocationId) {
            DB::table('packages')->whereNull('location_id')->update(['location_id' => $defaultLocationId]);
        }
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
