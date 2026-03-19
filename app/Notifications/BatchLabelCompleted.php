<?php

namespace App\Notifications;

use App\Enums\LabelBatchStatus;
use App\Filament\Resources\LabelBatchResource;
use App\Models\LabelBatch;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class BatchLabelCompleted extends Notification
{
    public function __construct(
        public LabelBatch $batch,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $status = $this->batch->status;

        return FilamentNotification::make()
            ->title(match ($status) {
                LabelBatchStatus::Completed => "Batch #{$this->batch->id} completed",
                LabelBatchStatus::CompletedWithErrors => "Batch #{$this->batch->id} completed with errors",
                LabelBatchStatus::Failed => "Batch #{$this->batch->id} failed",
                default => "Batch #{$this->batch->id} finished",
            })
            ->body("{$this->batch->successful_shipments} shipped, {$this->batch->failed_shipments} failed — \${$this->batch->total_cost}")
            ->icon(match ($status) {
                LabelBatchStatus::Completed => 'heroicon-o-check-circle',
                LabelBatchStatus::CompletedWithErrors => 'heroicon-o-exclamation-triangle',
                LabelBatchStatus::Failed => 'heroicon-o-x-circle',
                default => 'heroicon-o-paper-airplane',
            })
            ->iconColor(match ($status) {
                LabelBatchStatus::Completed => 'success',
                LabelBatchStatus::CompletedWithErrors => 'warning',
                LabelBatchStatus::Failed => 'danger',
                default => 'info',
            })
            ->actions([
                Action::make('view')
                    ->label('View Results')
                    ->url(LabelBatchResource::getUrl('view', ['record' => $this->batch])),
            ])
            ->getDatabaseMessage();
    }
}
