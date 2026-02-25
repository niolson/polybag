<?php

namespace App\Models;

use App\Enums\LabelBatchItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabelBatchItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'label_batch_id',
        'shipment_id',
        'package_id',
        'status',
        'tracking_number',
        'carrier',
        'service',
        'cost',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => LabelBatchItemStatus::class,
            'cost' => 'decimal:2',
        ];
    }

    public function labelBatch(): BelongsTo
    {
        return $this->belongsTo(LabelBatch::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
