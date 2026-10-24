<?php

use App\Contracts\DataSourceInterface;
use App\Jobs\RunDataSourceImportJob;
use App\Models\DataSource;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use App\Notifications\ImportCompleted;
use App\Notifications\ImportStopped;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\ErrorHandler\Error\FatalError;

uses(RefreshDatabase::class);

function fakeImportDriver(bool $failFetch = false): string
{
    $class = new class([]) implements DataSourceInterface
    {
        public static bool $shouldFail = false;

        public function __construct(array $config = []) // @phpstan-ignore constructor.unusedParameter
        {}

        public function fetchShipments(): Collection
        {
            if (self::$shouldFail) {
                throw new RuntimeException('Source unavailable');
            }

            return collect([[
                'shipment_reference' => 'JOB-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'address1' => '123 Main St',
                'city' => 'Seattle',
                'state_or_province' => 'WA',
                'postal_code' => '98101',
                'country' => 'US',
            ]]);
        }

        public function fetchShipmentItems(string $sourceRecordId): Collection
        {
            return collect();
        }

        public function validateConfiguration(): void {}

        public function getFieldMapping(): array
        {
            return [];
        }

        public function markExported(string $sourceRecordId): bool
        {
            return false;
        }
    };

    $className = get_class($class);
    $className::$shouldFail = $failFetch;

    return $className;
}

it('runs the import and notifies the triggering user on success', function (): void {
    Notification::fake();

    $user = User::factory()->admin()->create();
    $source = DataSource::factory()->create([
        'source_type' => fakeImportDriver(),
        'active' => true,
    ]);

    app()->call([new RunDataSourceImportJob($source->id, $user->id), 'handle']);

    expect(Shipment::where('shipment_reference', 'JOB-001')->exists())->toBeTrue();

    Notification::assertSentTo($user, ImportCompleted::class, function (ImportCompleted $notification) use ($source): bool {
        return $notification->sourceName === $source->name
            && $notification->stats['shipments_created'] === 1
            && $notification->errors === [];
    });
});

it('does nothing for an inactive source', function (): void {
    Notification::fake();

    $user = User::factory()->admin()->create();
    $source = DataSource::factory()->create([
        'source_type' => fakeImportDriver(),
        'active' => false,
    ]);

    app()->call([new RunDataSourceImportJob($source->id, $user->id), 'handle']);

    expect(Shipment::count())->toBe(0);
    Notification::assertNothingSent();
});

it('does nothing for an active connection with import turned off', function (): void {
    Notification::fake();

    $user = User::factory()->admin()->create();
    $source = DataSource::factory()->importDisabled()->create([
        'source_type' => fakeImportDriver(),
        'active' => true,
    ]);

    app()->call([new RunDataSourceImportJob($source->id, $user->id), 'handle']);

    expect(Shipment::count())->toBe(0);
    Notification::assertNothingSent();
});

it('does not run an Amazon import without a selected marketplace', function (): void {
    Notification::fake();
    Setting::updateOrCreate(['key' => 'require_mfa'], ['value' => '1', 'type' => 'boolean', 'group' => 'general']);
    app(SettingsService::class)->clearCache();

    $user = User::factory()->admin()->create();
    $source = DataSource::factory()->create([
        'source_type' => AmazonSource::class,
        'active' => true,
        'settings' => [
            'auth_mode' => 'authorization_code',
            'channel_name' => 'Amazon',
        ],
        'secret_settings' => ['refresh_token' => 'amazon-refresh-token'],
    ]);

    app()->call([new RunDataSourceImportJob($source->id, $user->id), 'handle']);

    Notification::assertSentTo($user, ImportCompleted::class, function (ImportCompleted $notification): bool {
        return $notification->errors === ['Choose an Amazon marketplace before running imports.'];
    });
});

it('leaves error notifications to the import pipeline when the import fails', function (): void {
    Notification::fake();

    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $source = DataSource::factory()->create([
        'source_type' => fakeImportDriver(failFetch: true),
        'active' => true,
    ]);

    app()->call([new RunDataSourceImportJob($source->id, $user->id), 'handle']);

    // The triggering user gets no success notification; admins are notified
    // of the failure by ImportRunRecorder.
    Notification::assertNotSentTo($user, ImportCompleted::class);
    Notification::assertSentTo($admin, ImportCompleted::class, function (ImportCompleted $notification): bool {
        return $notification->errors !== [];
    });
});

it('scopes the overlap lock to the data source id', function (): void {
    $job = new RunDataSourceImportJob(42, 1);

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('data-source-import:42')
        ->and($middleware[0]->releaseAfter)->toBe(60)
        ->and($middleware[0]->expiresAfter)->toBe(1860)
        ->and($job->timeout)->toBe(1800)
        ->and($job->tries)->toBe(60)
        ->and($job->maxExceptions)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->connection)->toBe('imports')
        ->and($job->queue)->toBe('imports')
        ->and(config('queue.connections.redis.retry_after'))->toBe(90)
        ->and(config('queue.connections.database.retry_after'))->toBe(90)
        ->and(config('queue.connections.imports.retry_after'))->toBe(1860);
});

it('tells the user who started an import and the active admins that it stopped', function (Throwable $exception, string $reason): void {
    $packer = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $inactiveAdmin = User::factory()->admin()->create(['active' => false]);
    $source = DataSource::factory()->create(['name' => 'Drop Day Store']);

    (new RunDataSourceImportJob($source->id, $packer->id))->failed($exception);

    foreach ([$packer, $admin] as $recipient) {
        $notification = $recipient->notifications()->sole();

        expect($notification->type)->toBe(ImportStopped::class)
            ->and($notification->data['title'])->toBe('Import Stopped (Drop Day Store)')
            ->and($notification->data['body'])->toBe($reason);
    }

    expect($inactiveAdmin->notifications()->count())->toBe(0);
})->with([
    'out of memory' => [
        fn (): FatalError => new FatalError('Allowed memory size of 134217728 bytes exhausted (tried to allocate 2097152 bytes)', 0, ['type' => E_ERROR, 'message' => '', 'file' => '', 'line' => 0], 0),
        'The import ran out of memory and stopped. Shipments it had already written are kept.',
    ],
    'another fatal error' => [
        fn (): FatalError => new FatalError('Call to undefined function', 0, ['type' => E_ERROR, 'message' => '', 'file' => '', 'line' => 0], 0),
        'The import stopped on a server error. Shipments it had already written are kept.',
    ],
    'timeout' => [
        fn (): TimeoutExceededException => new TimeoutExceededException('App\\Jobs\\RunDataSourceImportJob has timed out.'),
        'The import took longer than 30 minutes and was stopped. Shipments it had already written are kept.',
    ],
    'out of attempts behind another import' => [
        fn (): MaxAttemptsExceededException => new MaxAttemptsExceededException('has been attempted too many times.'),
        'Another import of this connection was still running, and this one stopped waiting for it.',
    ],
    'an exception' => [
        fn (): RuntimeException => new RuntimeException('Source unavailable'),
        'The import stopped: Source unavailable',
    ],
]);

it('notifies an admin who started an import once', function (): void {
    $admin = User::factory()->admin()->create();
    $source = DataSource::factory()->create();

    (new RunDataSourceImportJob($source->id, $admin->id))->failed(new RuntimeException('Source unavailable'));

    expect($admin->notifications()->count())->toBe(1);
});
