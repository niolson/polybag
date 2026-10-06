<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record when a validator last answered for a Shipment
     * (`address-validation-routing/02`), so an address no validator can settle
     * is not re-sent to the paid validators on every scheduled run, and count
     * the scheduled attempts so a source that keeps changing an address can't
     * keep re-validating it either.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('validation_attempted_at')->nullable()->after('validation_message');
            $table->unsignedSmallInteger('validation_attempts')->default(0)->after('validation_attempted_at');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['validation_attempted_at', 'validation_attempts']);
        });
    }
};
