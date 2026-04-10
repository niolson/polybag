<?php

use App\Enums\SpecialServiceMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure the saturday_delivery service exists before migrating data.
        // The SpecialServiceSeeder may not have run yet on upgrades.
        DB::table('special_services')->insertOrIgnore([
            'code' => 'saturday_delivery',
            'name' => 'Saturday Delivery',
            'description' => 'Request delivery on Saturday where available.',
            'scope' => 'shipment',
            'category' => 'delivery',
            'requires_value' => false,
            'config_schema' => null,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $serviceId = DB::table('special_services')->where('code', 'saturday_delivery')->value('id');

        if ($serviceId) {
            $methods = DB::table('shipping_methods')
                ->where('saturday_delivery', true)
                ->pluck('id');

            foreach ($methods as $methodId) {
                DB::table('shipping_method_special_service')->insertOrIgnore([
                    'shipping_method_id' => $methodId,
                    'special_service_id' => $serviceId,
                    'mode' => SpecialServiceMode::Default->value,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->dropColumn('saturday_delivery');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->boolean('saturday_delivery')->default(false);
        });
    }
};
