<?php

use App\Enums\ContentClass;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The contents a service is valid for, as a {@see ContentClass}. Null means
     * no requirement. A property of the service, so it binds every source that
     * sells it (ADR-0006 decision 10). Every service requires at most one
     * class, so one column holds it.
     */
    public function up(): void
    {
        Schema::table('carrier_services', function (Blueprint $table) {
            $table->string('required_contents', 32)->nullable()->after('can_ship_to_military_addresses');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_services', function (Blueprint $table) {
            $table->dropColumn('required_contents');
        });
    }
};
