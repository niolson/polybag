<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_reference');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->string('city');
            $table->string('state_or_province')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country');
            $table->boolean('residential')->default(true);
            $table->string('phone')->nullable();
            $table->string('phone_extension', 6)->nullable();
            $table->string('email')->nullable();
            $table->decimal('value', 8, 2)->nullable();

            // Address validation
            $table->boolean('checked')->default(false);
            $table->string('deliverability')->nullable();
            $table->text('validation_message')->nullable();
            $table->string('validated_company')->nullable();
            $table->string('validated_address1')->nullable();
            $table->string('validated_address2')->nullable();
            $table->string('validated_city')->nullable();
            $table->string('validated_state_or_province')->nullable();
            $table->string('validated_postal_code')->nullable();
            $table->string('validated_country')->nullable();
            $table->boolean('validated_residential')->nullable();

            $table->string('shipping_method_reference')->nullable();
            $table->foreignId('shipping_method_id')->nullable()->constrained();
            $table->foreignId('channel_id')->nullable()->constrained();
            $table->string('channel_reference')->nullable();
            $table->boolean('shipped')->default(false);
            $table->json('metadata')->nullable();
            $table->date('deliver_by')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'shipment_reference']);
            $table->index('shipped');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
