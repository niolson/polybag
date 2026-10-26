<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipment search matches the start of each value, so an index can serve it.
 */
return new class extends Migration
{
    private const COLUMNS = ['first_name', 'last_name', 'address1', 'city', 'email'];

    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->index($column);
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->dropIndex([$column]);
            }
        });
    }
};
