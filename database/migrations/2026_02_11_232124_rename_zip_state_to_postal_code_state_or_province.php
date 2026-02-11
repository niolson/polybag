<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->renameColumn('state', 'state_or_province');
            $table->renameColumn('zip', 'postal_code');
            $table->renameColumn('validated_state', 'validated_state_or_province');
            $table->renameColumn('validated_zip', 'validated_postal_code');
        });

        // Migrate stored settings keys
        DB::table('settings')->where('key', 'from_address.state')->update(['key' => 'from_address.state_or_province']);
        DB::table('settings')->where('key', 'from_address.zip')->update(['key' => 'from_address.postal_code']);
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->renameColumn('state_or_province', 'state');
            $table->renameColumn('postal_code', 'zip');
            $table->renameColumn('validated_state_or_province', 'validated_state');
            $table->renameColumn('validated_postal_code', 'validated_zip');
        });

        DB::table('settings')->where('key', 'from_address.state_or_province')->update(['key' => 'from_address.state']);
        DB::table('settings')->where('key', 'from_address.postal_code')->update(['key' => 'from_address.zip']);
    }
};
