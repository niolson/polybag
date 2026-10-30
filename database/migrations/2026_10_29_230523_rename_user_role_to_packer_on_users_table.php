<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The floor role is a packer, not a "user". The old name said nothing about
 * what the role does, and "shipper" was avoided because carrier APIs use it
 * for the sending party.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('packer')->change();
        });

        DB::table('users')->where('role', 'user')->update(['role' => 'packer']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->change();
        });

        DB::table('users')->where('role', 'packer')->update(['role' => 'user']);
    }
};
