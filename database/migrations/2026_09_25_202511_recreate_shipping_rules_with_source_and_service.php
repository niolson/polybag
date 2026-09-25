<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A rule names a source and a service — `carrier-catalog-reset/07`.
     *
     * Recreated rather than altered: no deployment holds a shipping rule, and
     * an old rule's catalog row cannot be read back as a source without
     * guessing.
     *
     * Both catalog foreign keys restrict deletion. A cascade would silently
     * drop an *Exclude* rule with the service it names, and a null-on-delete
     * would widen "Use UPS Ground" into "Use anything".
     */
    public function up(): void
    {
        Schema::dropIfExists('shipping_rules');

        Schema::create('shipping_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->foreignId('shipping_method_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('priority')->default(0);
            $table->json('conditions')->nullable();
            $table->string('action');

            // A `ShippingRuleSource`: a `PostageSourceKind`, or one of the two
            // *any* values that belong to rules alone.
            $table->string('source', 32);

            // One service, or `any_service`. Never both, never neither: a null
            // service does not stand for *any*.
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

        Schema::create('shipping_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->foreignId('shipping_method_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('priority')->default(0);
            $table->json('conditions')->nullable();
            $table->string('action');
            $table->foreignId('carrier_service_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index('priority');
            $table->index(['client_id', 'priority']);
        });
    }
};
