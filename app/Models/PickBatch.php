<?php

namespace App\Models;

use App\Enums\PickBatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PickBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'total_shipments',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PickBatchStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function isComplete(): bool
    {
        return in_array($this->status, [
            PickBatchStatus::Completed,
            PickBatchStatus::Cancelled,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pickBatchShipments(): HasMany
    {
        return $this->hasMany(PickBatchShipment::class);
    }

    public function shipments(): BelongsToMany
    {
        return $this->belongsToMany(Shipment::class, 'pick_batch_shipments')
            ->withPivot(['tote_code', 'picked_at'])
            ->withTimestamps();
    }
}
