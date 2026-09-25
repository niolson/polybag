<?php

namespace App\Models;

use App\Enums\HazmatClass;
use App\Models\Concerns\HasDefaultClient;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Searchable;

/**
 * @property HazmatClass|null $hazmat_class
 */
class Product extends Model
{
    use HasDefaultClient, HasFactory, Searchable;

    protected $fillable = [
        'client_id',
        'name',
        'sku',
        'barcode',
        'description',
        'weight',
        'handling_surcharge',
        'hs_tariff_number',
        'country_of_origin',
        'active',
        'contains_alcohol',
        'is_media',
        'hazmat_class',
        'bin_location',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'handling_surcharge' => 'decimal:2',
            'active' => 'boolean',
            'contains_alcohol' => 'boolean',
            'is_media' => 'boolean',
            'hazmat_class' => HazmatClass::class,
        ];
    }

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

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<ShipmentItem, $this>
     */
    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    /**
     * @return HasMany<PackageItem, $this>
     */
    public function packageItems(): HasMany
    {
        return $this->hasMany(PackageItem::class);
    }
}
