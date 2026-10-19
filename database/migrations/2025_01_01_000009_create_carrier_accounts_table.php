<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('credentials')->nullable();
            $table->text('secret_credentials')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            // An account's acceptance of USPS's prepaid-duties (DDP) terms (ADR-0008
            // decision 4). Until an Admin records it, the account gets no USPS DDP rates.
            $table->timestamp('ddp_terms_accepted_at')->nullable();
            $table->foreignId('ddp_terms_accepted_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_accounts');
    }
};
