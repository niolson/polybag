<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class ShipmentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_id',
        'product_id',
        'source_item_id',
        'quantity',
        'value',
        'transparency',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'transparency' => 'boolean',
    ];

    /**
     * Creating an item, or changing its product or quantity, increments the
     * Shipment's items version so a slip printed earlier shows as out of date.
     * Value and transparency are not on the slip, so they do not count.
     */
    protected static function booted(): void
    {
        static::created(fn (ShipmentItem $item) => Shipment::incrementItemsVersion($item->shipment_id));

        static::updated(function (ShipmentItem $item): void {
            if ($item->wasChanged('shipment_id')) {
                Shipment::incrementItemsVersion($item->getOriginal('shipment_id'), $item->shipment_id);
            } elseif ($item->wasChanged(['product_id', 'quantity'])) {
                Shipment::incrementItemsVersion($item->shipment_id);
            }
        });

        static::deleted(fn (ShipmentItem $item) => Shipment::incrementItemsVersion($item->shipment_id));
    }

    /**
     * The write and the version increment its events make commit together, so
     * an item can never change while its slip still looks current.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        return DB::transaction(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        return DB::transaction(fn (): ?bool => parent::delete());
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
