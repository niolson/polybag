<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierLocation extends Model
{
    protected $table = 'carrier_location';

    protected $fillable = [
        'carrier_id',
        'location_id',
        'pickup_days',
        'last_end_of_day_at',
    ];

    protected function casts(): array
    {
        return [
            'pickup_days' => 'array',
            'last_end_of_day_at' => 'datetime',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
