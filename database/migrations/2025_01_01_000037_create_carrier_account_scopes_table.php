<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ONE_TARGET_CONSTRAINT = 'carrier_account_scopes_one_target';

    public function up(): void
    {
        Schema::create('carrier_account_scopes', function (Blueprint $table) {
            $table->id();
            // A scope targets either a carrier account or, for Amazon Shipping sold to
            // orders from other channels, an Amazon connection (ADR-0002, 2026-09-22
            // amendment). Exactly one of the two is set.
            $table->foreignId('carrier_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained()->cascadeOnDelete();
            // carrier_id is denormalized here so the unique constraint can enforce
            // "only one account per (carrier, location, client) combination" at the DB level.
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            // Generated columns replace NULL with 0 so MySQL's unique index treats
            // nulls as equal (MySQL normally treats each NULL as distinct).
            $table->unsignedBigInteger('location_key')->virtualAs('COALESCE(location_id, 0)');
            $table->unsignedBigInteger('client_key')->virtualAs('COALESCE(client_id, 0)');
            $table->boolean('rate_shop')->default(false);
            $table->timestamps();
            $table->unique(['carrier_id', 'location_key', 'client_key'], 'carrier_account_scopes_unique');
        });

        // SQLite cannot add a check constraint to an existing table, so there
        // the scope's `saving` hook is the only enforcement.
        if ($this->supportsCheckConstraints()) {
            DB::statement('ALTER TABLE carrier_account_scopes ADD CONSTRAINT '.self::ONE_TARGET_CONSTRAINT
                .' CHECK ((carrier_account_id IS NULL) <> (data_source_id IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_account_scopes');
    }

    private function supportsCheckConstraints(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
