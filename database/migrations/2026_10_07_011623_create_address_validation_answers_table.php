<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per validator answer for a Shipment
     * (`address-validation-routing/04`), the evidence for revising the
     * validation routing from real orders. Rows hold verdicts, not addresses:
     * the address stays on the Shipment, so PII purging has nothing new to
     * reach, and the rows go when the Shipment does.
     */
    public function up(): void
    {
        Schema::create('address_validation_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('validator', 16);
            $table->boolean('paid');
            $table->string('outcome', 16);
            $table->string('deliverability', 16)->nullable();
            $table->string('reason', 32)->nullable();
            $table->string('country')->nullable();
            $table->string('trigger', 16);
            $table->timestamp('created_at')->nullable();

            $table->index(['validator', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_validation_answers');
    }
};
