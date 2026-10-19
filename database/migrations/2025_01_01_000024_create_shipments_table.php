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
            $table->foreignId('client_id')->constrained();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shipment_reference')->nullable();
            $table->string('source_record_id')->nullable();
            $table->string('source_checksum', 64)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->string('state_or_province')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country');
            $table->boolean('residential')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_e164')->nullable();
            $table->string('phone_extension', 6)->nullable();
            $table->string('email')->nullable();
            $table->decimal('value', 8, 2)->nullable();
            $table->boolean('checked')->default(false);
            $table->string('deliverability')->default('not_checked');
            $table->text('validation_message')->nullable();
            // The validator that settled the current validation result; null when none has.
            $table->string('validation_source', 16)->nullable();
            // When a validator last answered, so an address no validator can settle is not
            // re-sent to the paid validators on every scheduled run, and how many scheduled
            // attempts were made, so a source that keeps changing an address can't keep
            // re-validating it either.
            $table->timestamp('validation_attempted_at')->nullable();
            $table->unsignedSmallInteger('validation_attempts')->default(0);
            $table->string('validated_company')->nullable();
            $table->string('validated_address1')->nullable();
            $table->string('validated_address2')->nullable();
            $table->string('validated_city')->nullable();
            $table->string('validated_state_or_province')->nullable();
            $table->string('validated_postal_code')->nullable();
            $table->string('validated_country')->nullable();
            $table->boolean('validated_residential')->nullable();
            $table->string('validated_carrier_route')->nullable();
            $table->string('shipping_method_reference')->nullable();
            $table->foreignId('shipping_method_id')->nullable()->constrained();
            $table->foreignId('channel_id')->nullable()->constrained();
            $table->foreignId('data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->foreignId('data_source_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel_reference')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 16)->default('open');
            // Who set the status, and a tracking number from a shipment that went out
            // elsewhere (`order-reconciliation/02`).
            $table->string('status_origin', 16)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->string('external_carrier')->nullable();
            $table->string('external_tracking_number')->nullable();
            $table->string('picking_status', 16)->default('pending');
            // A Shipment's latest pack slip is recorded on the Shipment itself.
            // `items_version` moves whenever the Shipment's items change. A slip is out of
            // date when it has moved past `pack_slip_items_version`, the version the latest
            // recorded slip was drawn from; null means no slip was ever recorded. The
            // receipt's issue time, to the microsecond, orders two receipts of the same
            // version, so a late acknowledgment of an older document cannot overwrite a
            // newer print. The printed time and user are for display only.
            $table->unsignedInteger('items_version')->default(0);
            $table->unsignedInteger('pack_slip_items_version')->nullable();
            $table->dateTime('pack_slip_receipt_issued_at', 6)->nullable();
            $table->timestamp('pack_slip_printed_at')->nullable();
            $table->foreignId('pack_slip_printed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('deliver_by')->nullable();
            // The order's own customs terms (ADR-0008 decisions 2, 3 and 10), each filled by
            // an import or a manager's edit and null until then. `duties_terms` overrides the
            // client's duties policy; a seller registration replaces the client's;
            // `recipient_tax_id` is a national ID number, purged with the address.
            // A `DutiesTerms`.
            $table->string('duties_terms', 8)->nullable();
            // A `TaxRegistrationRegime`, and its number.
            $table->string('seller_tax_regime', 16)->nullable();
            $table->string('seller_tax_number', 20)->nullable();
            // A `RecipientTaxIdType`, and the ID.
            $table->string('recipient_tax_id_type', 16)->nullable();
            $table->string('recipient_tax_id', 50)->nullable();
            // `X` followed by 14 digits, once EEI is filed in AESDirect.
            $table->string('export_itn', 15)->nullable();
            // The ECB reference date the customs thresholds are converted at. Pinned by the
            // first resolution that converts a value, so whether a registration is under its
            // threshold cannot change because a later day's rates were fetched in between.
            // Not personal data, so not purged with the address.
            $table->date('customs_rate_date')->nullable();
            // When `PurgePiiCommand` cleared the recipient data.
            $table->timestamp('pii_purged_at')->nullable();
            $table->timestamps();

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->date('updated_date')->nullable()->storedAs('DATE(updated_at)');
            }

            $table->unique(['data_source_id', 'source_record_id'], 'shipments_data_source_record_unique');
            $table->index('shipment_reference');
            $table->index('created_at');
            $table->index('deliverability');
            $table->index('channel_id', 'shipments_channel_id_index');
            $table->index('channel_reference');
            $table->index('shipping_method_reference');
            $table->index('status');
            $table->index('external_tracking_number');
            $table->index('picking_status');
            $table->index('client_id');
            $table->index(['status', 'pack_slip_items_version'], 'shipments_pack_slip_queue_index');

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->index(['status', 'updated_date'], 'shipments_status_updated_date_index');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
