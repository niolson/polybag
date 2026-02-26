<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('label_batch_items', function (Blueprint $table) {
            $table->dropForeign(['shipment_id']);
            $table->unsignedBigInteger('shipment_id')->nullable()->change();
            $table->foreign('shipment_id')->references('id')->on('shipments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('label_batch_items', function (Blueprint $table) {
            $table->dropForeign(['shipment_id']);
            $table->unsignedBigInteger('shipment_id')->nullable(false)->change();
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
        });
    }
};
