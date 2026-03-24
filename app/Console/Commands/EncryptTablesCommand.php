<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EncryptTablesCommand extends Command
{
    protected $signature = 'db:encrypt-tables {--dry-run : Show tables that would be encrypted without making changes}';

    protected $description = 'Enable InnoDB encryption at rest for all tables';

    public function handle(): int
    {
        $tables = DB::select("
            SELECT table_name, create_options
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND engine = 'InnoDB'
        ");

        $unencrypted = collect($tables)->filter(
            fn ($t) => ! str_contains($t->create_options ?? '', 'ENCRYPTION=')
                    || str_contains($t->create_options ?? '', "ENCRYPTION='N'")
        );

        if ($unencrypted->isEmpty()) {
            $this->info('All tables are already encrypted.');

            return self::SUCCESS;
        }

        $this->info($unencrypted->count().' table(s) to encrypt:');

        foreach ($unencrypted as $table) {
            $name = $table->table_name;

            if ($this->option('dry-run')) {
                $this->line("  Would encrypt: {$name}");

                continue;
            }

            try {
                DB::statement("ALTER TABLE `{$name}` ENCRYPTION='Y'");
                $this->line("  Encrypted: {$name}");
            } catch (\Exception $e) {
                $this->error("  Failed to encrypt {$name}: {$e->getMessage()}");
            }
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run complete. No changes made.');
        } else {
            $this->info('Table encryption complete.');
        }

        return self::SUCCESS;
    }
}
