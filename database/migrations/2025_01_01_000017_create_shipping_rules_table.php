<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rules', function (Blueprint $table) {
            $table->id();
            // A rule goes with the shipping method or client it was written for: a null
            // method or client means every one, so null-on-delete would turn "Exclude
            // OnTrac" on one method into an exclusion on all of them.
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('shipping_method_id')->nullable()->constrained()->cascadeOnDelete();
            $table->integer('priority')->default(0);
            $table->json('conditions')->nullable();
            $table->string('action');
            // A `ShippingRuleSource`: a `PostageSourceKind`, or one of the two *any*
            // values that belong to rules alone.
            $table->string('source', 32);
            // One service, or `any_service`. Never both, never neither: a null service does
            // not stand for *any*. Both catalog foreign keys restrict deletion: a cascade
            // would silently drop an *Exclude* rule with the service it names, and a
            // null-on-delete would widen "Use UPS Ground" into "Use anything".
            $table->foreignId('carrier_service_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('any_service')->default(false);
            // *Exclude* only.
            $table->foreignId('carrier_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index('priority');
            $table->index(['client_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rules');
    }
};
