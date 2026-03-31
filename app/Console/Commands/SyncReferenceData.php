<?php

namespace App\Console\Commands;

use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Console\Command;

class SyncReferenceData extends Command
{
    protected $signature = 'app:sync-reference-data';

    protected $description = 'Sync tenant reference data such as carriers and carrier services';

    public function handle(): int
    {
        $this->info('Syncing reference data...');

        $this->callSilent('db:seed', [
            '--class' => ReferenceDataSeeder::class,
            '--force' => true,
        ]);

        $this->info('Reference data synced.');

        return self::SUCCESS;
    }
}
