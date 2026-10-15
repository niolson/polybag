<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The parts of `duties_cost`, when the carrier reports them: its package
     * fee, the customs duty and the import tax. All null when it reports only a
     * total, as a recovered USPS purchase does, so they may not add up to
     * `duties_cost` to the cent.
     */
    public function up(): void
    {
        Schema::table('package_labels', function (Blueprint $table): void {
            $table->decimal('duties_package_fee', 10, 2)->nullable()->after('duties_cost');
            $table->decimal('duties_duty', 10, 2)->nullable()->after('duties_package_fee');
            $table->decimal('duties_tax', 10, 2)->nullable()->after('duties_duty');
        });
    }

    public function down(): void
    {
        Schema::table('package_labels', function (Blueprint $table): void {
            $table->dropColumn(['duties_package_fee', 'duties_duty', 'duties_tax']);
        });
    }
};
