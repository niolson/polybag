<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // If settings already exist, this is an existing installation — mark setup as complete
        $existingInstall = DB::table('settings')->where('key', 'company_name')->exists();

        $now = now();

        DB::table('settings')->insertOrIgnore([
            [
                'key' => 'setup_complete',
                'value' => $existingInstall ? '1' : '0',
                'type' => 'boolean',
                'encrypted' => false,
                'group' => 'system',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'setup_wizard_step',
                'value' => '1',
                'type' => 'integer',
                'encrypted' => false,
                'group' => 'system',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['setup_complete', 'setup_wizard_step'])->delete();
    }
};
