<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickBatchShipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'pick_batch_id',
        'shipment_id',
        'tote_code',
        'picked_at',
    ];

    protected function casts(): array
    {
        return [
            'picked_at' => 'datetime',
        ];
    }

    public function pickBatch(): BelongsTo
    {
        return $this->belongsTo(PickBatch::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
