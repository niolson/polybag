<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->index('shipped_at');
            $table->index('carrier');
            $table->index('manifested');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->index('channel_reference');
            $table->index('shipping_method_reference');
        });

        Schema::table('label_batch_items', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('shipping_rules', function (Blueprint $table) {
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['shipped_at']);
            $table->dropIndex(['carrier']);
            $table->dropIndex(['manifested']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['channel_reference']);
            $table->dropIndex(['shipping_method_reference']);
        });

        Schema::table('label_batch_items', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('shipping_rules', function (Blueprint $table) {
            $table->dropIndex(['priority']);
        });
    }
};
