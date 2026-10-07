<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FedEx shadow answers and the evidence that would otherwise go with the
     * address (`address-validation-routing/10`). The comparison flags say
     * whether FedEx returned the same address as the answer it shadows,
     * without keeping either address; `address_changed_at` records an edit
     * after validation, which otherwise survives only in the audit log.
     */
    public function up(): void
    {
        Schema::table('address_validation_answers', function (Blueprint $table): void {
            $table->boolean('shadow')->default(false)->after('trigger');
            $table->foreignId('shadows_answer_id')->nullable()->unique()->after('shadow')
                ->constrained('address_validation_answers')->cascadeOnDelete();
            $table->boolean('street_differs')->nullable()->after('shadows_answer_id');
            $table->boolean('house_number_differs')->nullable()->after('street_differs');
            $table->boolean('city_differs')->nullable()->after('house_number_differs');
            $table->boolean('postcode_differs')->nullable()->after('city_differs');
            $table->timestamp('address_changed_at')->nullable()->after('postcode_differs');
        });
    }

    public function down(): void
    {
        Schema::table('address_validation_answers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shadows_answer_id');
            $table->dropColumn([
                'shadow',
                'street_differs',
                'house_number_differs',
                'city_differs',
                'postcode_differs',
                'address_changed_at',
            ]);
        });
    }
};
