<?php

use App\Jobs\RunDataSourceImportJob;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\User;
use App\Notifications\ImportStopped;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\Queue\ExhaustsMemory;
use Tests\Fixtures\Queue\ExhaustsMemoryImportSource;
use Tests\Fixtures\Queue\ExhaustsMemoryJob;

/*
 * A real fatal error, in a real `queue:work` process: a shutdown function
 * cannot be exercised from inside the test process. The worker and the test
 * share a SQLite file, since each has its own in-memory database otherwise.
 */

beforeEach(function (): void {
    $this->workerDatabase = tempnam(sys_get_temp_dir(), 'polybag-fatal-job-');

    config([
        'database.connections.worker' => [
            ...config('database.connections.sqlite'),
            'database' => $this->workerDatabase,
        ],
        'queue.connections.worker' => [
            'driver' => 'database',
            'connection' => 'worker',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
    ]);

    Artisan::call('migrate', ['--database' => 'worker', '--force' => true]);
});

afterEach(function (): void {
    DB::purge('worker');
    @unlink($this->workerDatabase);
});

function runWorkerOnce(string $databasePath, string $queue): ProcessResult
{
    return Process::path(base_path())
        ->timeout(120)
        ->env([
            'APP_ENV' => 'testing',
            'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $databasePath,
            'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'SENTRY_LARAVEL_DSN' => '',
            'SHIPMENT_IMPORT_MEMORY_LIMIT' => '64M',
        ])
        ->run([PHP_BINARY, '-d', 'memory_limit=64M', 'artisan', 'queue:work', 'database', "--queue={$queue}", '--once', '--tries=3']);
}

it('fails a queued job that runs out of memory instead of leaving it to be retried', function (): void {
    app('queue')->connection('worker')->pushOn('default', new ExhaustsMemoryJob);

    $result = runWorkerOnce($this->workerDatabase, 'default');

    $worker = DB::connection('worker');

    expect($result->successful())->toBeFalse()
        ->and($result->output().$result->errorOutput())->toContain('Allowed memory size')
        ->and($worker->table('jobs')->count())->toBe(0)
        ->and($worker->table('failed_jobs')->count())->toBe(1)
        ->and($worker->table('failed_jobs')->value('exception'))->toContain('Allowed memory size');
});

it('tells the user and the active admins when an import runs out of memory inside a transaction', function (): void {
    $packer = User::factory()->connection('worker')->create();
    $admin = User::factory()->connection('worker')->admin()->create();
    $inactiveAdmin = User::factory()->connection('worker')->admin()->create(['active' => false]);
    $source = DataSource::factory()->connection('worker')->create([
        'client_id' => Client::factory()->connection('worker')->create()->id,
        'name' => 'Drop Day Store',
        'source_type' => ExhaustsMemoryImportSource::class,
    ]);

    app('queue')->connection('worker')->pushOn('imports', new RunDataSourceImportJob($source->id, $packer->id));

    $result = runWorkerOnce($this->workerDatabase, 'imports');

    $worker = DB::connection('worker');

    expect($result->successful())->toBeFalse()
        ->and($worker->table('jobs')->count())->toBe(0)
        ->and($worker->table('failed_jobs')->count())->toBe(1)
        // The rollback undid the write the fatal interrupted, and what the
        // failure wrote afterwards survived the process exiting.
        ->and($worker->table('cache')->where('key', ExhaustsMemory::WRITTEN_INSIDE_TRANSACTION)->exists())->toBeFalse();

    foreach ([$packer, $admin] as $recipient) {
        $notification = $recipient->notifications()->sole();

        expect($notification->type)->toBe(ImportStopped::class)
            ->and($notification->data['title'])->toBe('Import Stopped (Drop Day Store)')
            ->and($notification->data['body'])->toContain('ran out of memory');
    }

    expect($inactiveAdmin->notifications()->count())->toBe(0);
});
