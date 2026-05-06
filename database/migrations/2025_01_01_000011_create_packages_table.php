<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('box_size_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tracking_number')->nullable();
            $table->string('carrier')->nullable();
            $table->string('service')->nullable();
            $table->longText('metadata')->nullable();
            $table->json('carrier_request_payload')->nullable();
            $table->longText('label_data')->nullable();
            $table->string('label_orientation')->nullable();
            $table->string('label_format', 10)->default('pdf');
            $table->unsignedSmallInteger('label_dpi')->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->boolean('weight_mismatch')->default(false);
            $table->string('status', 16)->default('unshipped');
            $table->string('tracking_status')->nullable();
            $table->timestamp('tracking_updated_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('tracking_details')->nullable();
            $table->timestamp('tracking_checked_at')->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('cost', 8, 2)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->date('ship_date')->nullable();
            $table->foreignId('shipped_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('exported')->default(false);
            $table->foreignId('manifest_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('tracking_number');
            $table->index('shipped_at');
            $table->index('carrier');
            $table->index('ship_date');
            $table->index('tracking_status');
            $table->index('tracking_checked_at');
            $table->index(['status', 'shipped_at'], 'packages_status_shipped_at_index');
            $table->index(['status', 'weight_mismatch', 'shipped_at'], 'packages_status_weight_mismatch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
