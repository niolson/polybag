<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Searchable;

class Product extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'name',
        'sku',
        'barcode',
        'description',
        'weight',
        'hs_tariff_number',
        'country_of_origin',
        'active',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
        'active' => 'boolean',
    ];

    /**
     * @return array<string, mixed>
     */
    #[SearchUsingPrefix(['sku', 'barcode'])]
    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
        ];
    }

    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function packageItems(): HasMany
    {
        return $this->hasMany(PackageItem::class);
    }
}
