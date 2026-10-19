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
            // FedEx shadow answers and the evidence that would otherwise go with the
            // address (`address-validation-routing/10`). The comparison flags say whether
            // FedEx returned the same address as the answer it shadows, without keeping
            // either address.
            $table->boolean('shadow')->default(false);
            $table->foreignId('shadows_answer_id')->nullable()->unique()
                ->constrained('address_validation_answers')->cascadeOnDelete();
            $table->boolean('street_differs')->nullable();
            $table->boolean('house_number_differs')->nullable();
            $table->boolean('city_differs')->nullable();
            $table->boolean('postcode_differs')->nullable();
            // An edit after validation, which otherwise survives only in the audit log, and
            // which part of the address it changed (`address-validation-routing/11`): adding
            // a missing unit and correcting a wrong street say different things about the
            // validator. Flags rather than the old or new values.
            $table->timestamp('address_changed_at')->nullable();
            $table->boolean('street_changed')->nullable();
            $table->boolean('unit_changed')->nullable();
            $table->boolean('locality_changed')->nullable();
            $table->boolean('postcode_changed')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['validator', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_validation_answers');
    }
};
