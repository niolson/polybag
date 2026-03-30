<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

class GenerateSshKey extends Command
{
    protected $signature = 'app:generate-ssh-key {--force : Overwrite existing keypair without prompting}';

    protected $description = 'Generate an SSH keypair for database import tunneling';

    public function handle(): int
    {
        $privateKeyPath = storage_path('app/private/ssh/id_ed25519');
        $publicKeyPath = $privateKeyPath.'.pub';

        if (file_exists($privateKeyPath)) {
            if (! $this->option('force') && ! confirm('Existing SSH keypair found. Overwrite?', default: false)) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }

            unlink($privateKeyPath);
            if (file_exists($publicKeyPath)) {
                unlink($publicKeyPath);
            }
        }

        @mkdir(dirname($privateKeyPath), 0775, recursive: true);

        $result = null;
        $output = null;
        exec(
            sprintf(
                'ssh-keygen -t ed25519 -f %s -N "" -q -C "polybag-import"',
                escapeshellarg($privateKeyPath),
            ),
            $output,
            $result,
        );

        if ($result !== 0) {
            $this->error('Failed to generate SSH keypair. Is ssh-keygen available?');

            return self::FAILURE;
        }

        chmod($privateKeyPath, 0600);

        $this->info("Private key: {$privateKeyPath}");
        $this->info("Public key:  {$publicKeyPath}");
        $this->newLine();
        $this->info('Public key content:');
        $this->line(file_get_contents($publicKeyPath));

        return self::SUCCESS;
    }
}
