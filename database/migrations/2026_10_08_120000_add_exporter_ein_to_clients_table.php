<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The client's own EIN, sent with an export ITN — `international-customs-terms/11`.
     *
     * Nine digits, no hyphen. Origin-side, so not a `client_tax_registrations` row.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('exporter_ein', 9)->nullable()->after('duties_policy');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('exporter_ein');
        });
    }
};
