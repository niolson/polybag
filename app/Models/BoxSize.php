<?php

namespace App\Models;

use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Services\CacheService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BoxSize extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn () => app(CacheService::class)->clearBoxSizesCache());
        static::deleted(fn () => app(CacheService::class)->clearBoxSizesCache());
    }

    protected $fillable = [
        'height',
        'width',
        'length',
        'max_weight',
        'empty_weight',
        'label',
        'code',
        'type',
        'carrier_packaging',
        'materials_cost',
    ];

    protected $casts = [
        'height' => 'decimal:2',
        'width' => 'decimal:2',
        'length' => 'decimal:2',
        'max_weight' => 'decimal:2',
        'empty_weight' => 'decimal:2',
        'type' => BoxSizeType::class,
        'carrier_packaging' => CarrierPackaging::class,
        'materials_cost' => 'decimal:2',
    ];
}
