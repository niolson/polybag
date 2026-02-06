<?php

namespace App\Models;

use App\Enums\BoxSizeType;
use App\Enums\FedexPackageType;
use App\Services\CacheService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BoxSize extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn () => CacheService::clearBoxSizesCache());
        static::deleted(fn () => CacheService::clearBoxSizesCache());
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
        'fedex_package_type',
    ];

    protected $casts = [
        'height' => 'decimal:2',
        'width' => 'decimal:2',
        'length' => 'decimal:2',
        'max_weight' => 'decimal:2',
        'empty_weight' => 'decimal:2',
        'type' => BoxSizeType::class,
        'fedex_package_type' => FedexPackageType::class,
    ];

    public function carrierServices(): BelongsToMany
    {
        return $this->belongsToMany(CarrierService::class);
    }
}
