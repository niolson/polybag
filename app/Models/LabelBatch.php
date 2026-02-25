<?php

namespace App\Models;

use App\Enums\LabelBatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabelBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'bus_batch_id',
        'user_id',
        'box_size_id',
        'label_format',
        'label_dpi',
        'status',
        'total_shipments',
        'successful_shipments',
        'failed_shipments',
        'total_cost',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => LabelBatchStatus::class,
            'total_cost' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function boxSize(): BelongsTo
    {
        return $this->belongsTo(BoxSize::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LabelBatchItem::class);
    }

    public function isComplete(): bool
    {
        return in_array($this->status, [
            LabelBatchStatus::Completed,
            LabelBatchStatus::CompletedWithErrors,
            LabelBatchStatus::Failed,
        ]);
    }
}
