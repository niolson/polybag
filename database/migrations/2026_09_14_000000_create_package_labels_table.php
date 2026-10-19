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
            // The digest of the account's billing identity (`CarrierAccount::fingerprint()`).
            // `carrier_account_id` is null-on-delete, so once the account is deleted the id
            // alone cannot tell "bought on an account that no longer exists" from "bought
            // before accounts were recorded"; only the second may fall back to scope
            // resolution. A direct Label is voided and tracked on the account that bought it.
            $table->string('carrier_account_fingerprint', 64)->nullable();
            $table->foreignId('postage_data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->string('carrier')->nullable();
            $table->foreignId('normalized_carrier_id')->nullable()->constrained('carriers')->restrictOnDelete();
            // The catalog service a Label was bought as. Beside `normalized_carrier_id`,
            // and restricting deletion as it does: a Label is history. Null for a blind
            // purchase; what Shopify was asked for stays the requested preference
            // (ADR-0003 decision 7), never the service bought.
            $table->foreignId('carrier_service_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('service')->nullable();
            $table->string('requested_service')->nullable();
            $table->decimal('cost', 8, 2)->nullable();
            $table->string('label_format', 10)->default('pdf');
            $table->unsignedSmallInteger('label_dpi')->nullable();
            $table->string('label_orientation')->nullable();
            $table->date('ship_date')->nullable();
            // Which manifest the Label was on, kept after the Label is voided:
            // `packages.manifest_id` describes the active Label, and a void clears it so
            // the re-shipped Label reaches the next SCAN form.
            $table->foreignId('manifest_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('purchased_at')->nullable();
            $table->foreignId('purchased_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // The postage source's own identifier for the label (Shopify label
            // ID, Amazon shipment ID). Written at purchase: both sources strip
            // it from `packages.metadata` on void so a dead purchase cannot be
            // resumed, so it has to be recorded before the void.
            $table->string('source_label_reference')->nullable();
            // The Shopify fulfillment an export created for the Label. A void cancels it so
            // Shopify stops showing the dead tracking number. It lives here because the void
            // deletes the Package's export rows, and the fulfillment belongs to one Label.
            $table->string('shopify_fulfillment_id')->nullable();
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
            // What the Label declared at purchase (ADR-0008 decision 6). `customs_terms` is a
            // snapshot of the resolved duties term, seller registration and ITN, and never
            // holds the recipient's tax ID. `duties_cost` is the prepaid duties amount a
            // carrier reports; its parts are the package fee, the customs duty and the import
            // tax, all null when the carrier reports only a total, so they may not add up to
            // `duties_cost` to the cent.
            $table->json('customs_terms')->nullable();
            $table->decimal('duties_cost', 10, 2)->nullable();
            $table->decimal('duties_package_fee', 10, 2)->nullable();
            $table->decimal('duties_duty', 10, 2)->nullable();
            $table->decimal('duties_tax', 10, 2)->nullable();
            $table->unique('active_package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_labels');
    }
};
