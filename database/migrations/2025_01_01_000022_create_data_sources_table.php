<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('name');
            $table->string('source_type');
            // `active` means the connection may be used at all; `import_enabled`
            // separately decides whether it imports orders.
            $table->boolean('active')->default(true);
            $table->boolean('import_enabled')->default(true);
            // Lets an Amazon connection sell Amazon Shipping for orders from other
            // channels, chosen by a `carrier_account_scopes` row that targets the
            // connection (ADR-0002, 2026-09-22 amendment).
            $table->boolean('offers_off_amazon_shipping')->default(false);
            // A `PostageSetting`, null for a driver that sells no postage (ADR-0006
            // decision 6).
            $table->string('postage_setting', 16)->nullable();
            $table->string('off_amazon_shipping_status')->nullable();
            $table->timestamp('off_amazon_shipping_checked_at')->nullable();
            $table->boolean('global_export')->default(false);
            $table->string('schedule_interval')->nullable();
            // How far an import has read its source's changes. An Amazon import asks only
            // for orders updated since `import_updated_through`, open and closed alike, and
            // reads every open order again once `import_swept_at` is a day old.
            // `import_reads` holds a search Amazon throttled partway (its query and
            // pagination token, good for 24 hours); `import_account_key` names the seller
            // account and marketplace all three were read from.
            $table->timestamp('import_updated_through')->nullable();
            $table->timestamp('import_swept_at')->nullable();
            $table->json('import_reads')->nullable();
            $table->string('import_account_key', 64)->nullable();
            // When one of the connection's locations was last mapped, unmapped or ignored.
            // That changes what an import keeps without changing anything at the source,
            // so the next run reads every open record instead of searching for changes.
            $table->timestamp('locations_remapped_at')->nullable();
            $table->json('settings')->nullable();
            $table->longText('secret_settings')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};
