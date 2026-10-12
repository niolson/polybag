<?php

namespace App\Filament\Resources\ShipmentResource\Pages;

use App\Enums\AuditAction;
use App\Enums\PackageStatus;
use App\Enums\ShipmentStatus;
use App\Enums\ShipmentStatusOrigin;
use App\Filament\Resources\ShipmentResource;
use App\Models\AuditLog;
use App\Models\Shipment;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use LogicException;

class EditShipment extends EditRecord
{
    protected static string $resource = ShipmentResource::class;

    /**
     * The status and origin before this save, for the audit row.
     *
     * @var array{status: ?string, status_origin: ?string}|null
     */
    private ?array $statusBeforeSave = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action): void {
                    if ($this->record->packages()->where('status', PackageStatus::Shipped)->exists()) {
                        Notification::make()
                            ->title('Cannot delete shipment')
                            ->body('This shipment has shipped packages. Void the labels first before deleting.')
                            ->danger()
                            ->send();

                        $action->cancel();
                    }
                }),
        ];
    }

    /**
     * A status set here is a manager's (`order-reconciliation/02`), whoever
     * set it before. Only Managers and Admins can edit a Shipment at all.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $incoming = $data['status'] ?? null;
        $incoming = $incoming instanceof ShipmentStatus ? $incoming : ShipmentStatus::tryFrom((string) $incoming);

        $shipment = $this->shipment();

        if ($incoming !== null && $incoming !== $shipment->status) {
            $this->statusBeforeSave = [
                'status' => $shipment->status->value,
                'status_origin' => $shipment->status_origin?->value,
            ];
            $data['status_origin'] = ShipmentStatusOrigin::Manager;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->statusBeforeSave === null) {
            return;
        }

        $shipment = $this->shipment();

        AuditLog::record(
            AuditAction::ShipmentUpdated,
            $shipment,
            oldValues: $this->statusBeforeSave,
            newValues: [
                'status' => $shipment->status->value,
                'status_origin' => $shipment->status_origin?->value,
            ],
        );

        $this->statusBeforeSave = null;
    }

    private function shipment(): Shipment
    {
        $record = $this->getRecord();

        if (! $record instanceof Shipment) {
            throw new LogicException('Edit Shipment was opened for a record that is not a Shipment.');
        }

        return $record;
    }
}
