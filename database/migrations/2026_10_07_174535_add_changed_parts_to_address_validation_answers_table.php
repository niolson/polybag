<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which part of the address an edit after validation changed
     * (`address-validation-routing/11`): adding a missing unit and correcting
     * a wrong street say different things about the validator. Set with
     * `address_changed_at`, as flags rather than the old or new values.
     */
    public function up(): void
    {
        Schema::table('address_validation_answers', function (Blueprint $table): void {
            $table->boolean('street_changed')->nullable()->after('address_changed_at');
            $table->boolean('unit_changed')->nullable()->after('street_changed');
            $table->boolean('locality_changed')->nullable()->after('unit_changed');
            $table->boolean('postcode_changed')->nullable()->after('locality_changed');
        });
    }

    public function down(): void
    {
        Schema::table('address_validation_answers', function (Blueprint $table): void {
            $table->dropColumn(['street_changed', 'unit_changed', 'locality_changed', 'postcode_changed']);
        });
    }
};
