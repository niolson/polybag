<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('logo')->nullable();
            $table->string('company_name')->nullable();
            $table->text('custom_message')->nullable();
            $table->text('return_instructions')->nullable();
            $table->string('return_company')->nullable();
            $table->string('return_name')->nullable();
            $table->string('return_address1')->nullable();
            $table->string('return_address2')->nullable();
            $table->string('return_city')->nullable();
            $table->string('return_state_or_province')->nullable();
            $table->string('return_postal_code')->nullable();
            $table->string('return_country', 2)->nullable();
            $table->string('return_phone')->nullable();
            $table->decimal('pick_fee_first_item', 8, 2)->nullable();
            $table->decimal('pick_fee_additional_item', 8, 2)->nullable();
            $table->decimal('label_fee_per_package', 8, 2)->nullable();
            $table->string('label_reference_source', 40)->nullable();
            // A map from destination (`EU` or an ISO 3166 alpha-2 code) to `ddp` or
            // `ddu` (ADR-0008 decision 1). Null and empty both mean nothing has been
            // chosen, which is where every client starts: no default would be safe.
            $table->json('duties_policy')->nullable();
            // The client's own EIN, sent with an export ITN. Nine digits, no hyphen.
            // Origin-side, so not a `client_tax_registrations` row.
            $table->string('exporter_ein', 9)->nullable();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
