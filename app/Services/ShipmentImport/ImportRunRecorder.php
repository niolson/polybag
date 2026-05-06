<?php

namespace App\Services\ShipmentImport;

use App\Contracts\ImportSourceInterface;
use App\Enums\Role;
use App\Events\ImportCompleted;
use App\Events\ShipmentImported;
use App\Events\ShipmentUpdated;
use App\Models\Shipment;
use App\Models\User;
use App\Notifications\ImportCompleted as ImportCompletedNotification;
use Illuminate\Support\Facades\Log;

class ImportRunRecorder
{
    /** @var array<string, int> */
    private array $stats = [
        'shipments_created' => 0,
        'shipments_updated' => 0,
        'items_created' => 0,
        'items_updated' => 0,
        'products_created' => 0,
        'products_updated' => 0,
        'shipments_exported' => 0,
    ];

    /** @var array<int, string> */
    private array $errors = [];

    public function __construct(
        private readonly string $sourceName,
    ) {}

    public static function forSource(ImportSourceInterface $source): self
    {
        return new self($source->getSourceName());
    }

    public function configurationFailed(string $message, float $duration): ImportResult
    {
        $this->notifyAdmins(new ImportCompletedNotification([], $this->sourceName, [$message]));

        return new ImportResult(errors: [$message], duration: $duration);
    }

    public function started(int $shipmentCount): void
    {
        $this->log('info', "Starting import from {$this->sourceName}", [
            'shipment_count' => $shipmentCount,
        ]);
    }

    /**
     * @param  array<string, int>  $stats
     */
    public function addStats(array $stats): void
    {
        foreach ($stats as $key => $value) {
            $this->stats[$key] += $value;
        }
    }

    public function increment(string $key): void
    {
        $this->stats[$key]++;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function recordImportError(array $data, \Exception $exception): void
    {
        $this->addError("Error importing shipment {$data['shipment_reference']}: ".$exception->getMessage());

        $this->log('error', 'Import error', [
            'shipment_reference' => $data['shipment_reference'] ?? 'unknown',
            'error' => $exception->getMessage(),
        ]);
    }

    public function recordShipmentEvent(Shipment $shipment, bool $wasExisting): void
    {
        if ($wasExisting) {
            ShipmentUpdated::dispatch($shipment);

            return;
        }

        ShipmentImported::dispatch($shipment);
    }

    public function recordSourceExportFailure(string $sourceRecordId, array $data, \Exception $exception): void
    {
        $this->addError("Error marking shipment {$sourceRecordId} as exported: ".$exception->getMessage());

        $this->log('warning', 'Failed to mark shipment as exported', [
            'shipment_reference' => $data['shipment_reference'] ?? $sourceRecordId,
            'source_record_id' => $sourceRecordId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function completed(float $duration): ImportResult
    {
        $this->log('info', 'Import completed', array_merge($this->stats, ['duration' => $duration]));

        ImportCompleted::dispatch($this->stats, $this->sourceName);

        $this->notifyAdmins(
            new ImportCompletedNotification($this->stats, $this->sourceName, $this->errors)
        );

        return new ImportResult(
            shipmentsCreated: $this->stats['shipments_created'],
            shipmentsUpdated: $this->stats['shipments_updated'],
            itemsCreated: $this->stats['items_created'],
            itemsUpdated: $this->stats['items_updated'],
            productsCreated: $this->stats['products_created'],
            productsUpdated: $this->stats['products_updated'],
            shipmentsExported: $this->stats['shipments_exported'],
            errors: $this->errors,
            duration: $duration
        );
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $channel = config('shipment-import.logging.channel', 'stack');
        Log::channel($channel)->log($level, $message, $context);
    }

    private function notifyAdmins(ImportCompletedNotification $notification): void
    {
        User::where('role', Role::Admin)->where('active', true)->get()
            ->each->notify($notification);
    }
}
