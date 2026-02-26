<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RateQuote extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'package_id',
        'carrier',
        'service_code',
        'service_name',
        'quoted_price',
        'quoted_delivery_date',
        'transit_time',
        'selected',
    ];

    protected $casts = [
        'quoted_price' => 'decimal:2',
        'selected' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
