<?php

namespace App\Models;

use App\Enums\BoxSizeType;
use App\Enums\CarrierPackaging;
use App\Enums\FedexPackageType;
use App\Services\CacheService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarrierPackaging|null $carrier_packaging Whose packaging this is when it is not the packer's own — ADR-0005's second axis. The column and its cast arrive in packaging-form-and-carrier-identity/03; until then it reads null unless a test sets it by hand.
 */
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
        'fedex_package_type',
        'materials_cost',
    ];

    protected $casts = [
        'height' => 'decimal:2',
        'width' => 'decimal:2',
        'length' => 'decimal:2',
        'max_weight' => 'decimal:2',
        'empty_weight' => 'decimal:2',
        'type' => BoxSizeType::class,
        'fedex_package_type' => FedexPackageType::class,
        'materials_cost' => 'decimal:2',
    ];
}
