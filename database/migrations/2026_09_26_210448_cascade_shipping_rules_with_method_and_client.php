<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A rule goes with the shipping method or client it was written for.
     *
     * Both keys were null-on-delete, and a null method or client means every
     * one, so deleting either turned its rules loose on the rest: "Exclude
     * OnTrac" on one method became an exclusion on all of them. The catalog
     * keys restrict deletion for the same reason (`carrier-catalog-reset/07`).
     * These cascade instead, because a rule scoped to something gone has
     * nothing left to apply to.
     */
    public function up(): void
    {
        Schema::table('shipping_rules', function (Blueprint $table) {
            $table->dropForeign(['shipping_method_id']);
            $table->dropForeign(['client_id']);
        });

        Schema::table('shipping_rules', function (Blueprint $table) {
            $table->foreign('shipping_method_id')->references('id')->on('shipping_methods')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipping_rules', function (Blueprint $table) {
            $table->dropForeign(['shipping_method_id']);
            $table->dropForeign(['client_id']);
        });

        Schema::table('shipping_rules', function (Blueprint $table) {
            $table->foreign('shipping_method_id')->references('id')->on('shipping_methods')->nullOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });
    }
};
