<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per purchased label, kept through the void that clears the
     * package. See ADR-0004.
     *
     * Every projected column mirrors the nullability and default of its
     * `packages` counterpart: a fixture created with a bare `shipped` status
     * gets the same database defaults a Package does, and a Shopify purchase
     * can legitimately name no carrier of record. The three pointer foreign
     * keys carry the same delete rules as on `packages` — an engine-default
     * RESTRICT here would make deleting a carrier account, data source or user
     * that ever bought a label throw, and a pointer nulled on one side only
     * would read as projection drift.
     *
     * No `label_data` or `customs_form_data` (ADR-0004 decision 6) and no
     * `shipment_id` — a label reaches its Shipment through its Package.
     */
    public function up(): void
    {
        Schema::create('package_labels', function (Blueprint $table) {
            $table->id();
            // Not unique: one package, many labels over time.
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('tracking_number')->nullable()->index();
            $table->string('postage_source', 32)->nullable();
            $table->foreignId('carrier_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('postage_data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->string('carrier')->nullable();
            $table->foreignId('normalized_carrier_id')->nullable()->constrained('carriers')->restrictOnDelete();
            $table->string('service')->nullable();
            $table->string('requested_service')->nullable();
            $table->string('service_evidence', 16)->default('unknown');
            $table->string('service_inference_method', 64)->nullable();
            $table->string('service_ruleset_version', 32)->nullable();
            $table->decimal('cost', 8, 2)->nullable();
            $table->string('label_format', 10)->default('pdf');
            $table->unsignedSmallInteger('label_dpi')->nullable();
            $table->string('label_orientation')->nullable();
            $table->date('ship_date')->nullable();
            $table->timestamp('purchased_at')->nullable();
            $table->foreignId('purchased_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // The postage source's own identifier for the label (Shopify label
            // ID, Amazon shipment ID). Written at purchase: both sources strip
            // it from `packages.metadata` on void so a dead purchase cannot be
            // resumed, so it has to be recorded before the void.
            $table->string('source_label_reference')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 32)->nullable();
            $table->timestamp('last_printed_at')->nullable();
            $table->timestamps();

            // `package_id` while unvoided, NULL once voided, so a unique index
            // over it makes "at most one active label per package" a database
            // fact — against any writer, including one that does not exist yet.
            // Virtual rather than stored: both MySQL 8.4 (InnoDB secondary
            // index on a virtual column) and SQLite index it, and it is never
            // read directly.
            $table->unsignedBigInteger('active_package_id')
                ->nullable()
                ->virtualAs('case when voided_at is null then package_id else null end');
            $table->unique('active_package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_labels');
    }
};
