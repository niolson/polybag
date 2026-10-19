<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_form_requests', function (Blueprint $table) {
            $table->id();
            $table->char('request_hash', 64)->unique();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_form_requests');
    }
};
