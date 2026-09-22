<?php

namespace App\Jobs;

use App\Models\DataSource;
use App\Models\User;
use App\Notifications\ImportCompleted;
use App\Services\SettingsService;
use App\Services\ShipmentImport\ShipmentImportService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RunDataSourceImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 60;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $dataSourceId,
        public int $userId,
        /** @var array<string, mixed> */
        public array $sourceOverrides = [],
    ) {
        $this->onConnection('imports')->onQueue('imports');
    }

    /**
     * Prevent concurrent imports of the same source (double-clicks, scheduler overlap).
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("data-source-import:{$this->dataSourceId}"))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(SettingsService $settings): void
    {
        $source = DataSource::find($this->dataSourceId);

        if (! $source || ! $source->importsOrders()) {
            return;
        }

        if ($source->source_type === AmazonSource::class && ! $settings->get('require_mfa', false)) {
            User::find($this->userId)?->notify(
                new ImportCompleted([], $source->name, ['Amazon SP-API imports require Multi-Factor Authentication to be enabled. Enable it in App Settings → Authentication.'])
            );

            return;
        }

        if ($source->source_type === AmazonSource::class && blank($source->settings['marketplace_id'] ?? null)) {
            User::find($this->userId)?->notify(
                new ImportCompleted([], $source->name, ['Choose an Amazon marketplace before running imports.'])
            );

            return;
        }

        $result = ShipmentImportService::forRecord($source, $this->sourceOverrides)->import();

        // ImportRunRecorder already notifies all active admins when an import
        // finishes with errors; only the success confirmation is on us here.
        if ($result->hasErrors()) {
            return;
        }

        User::find($this->userId)?->notify(
            new ImportCompleted($result->toArray(), $source->name)
        );
    }
}
