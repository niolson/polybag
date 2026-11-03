<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_id',
        'shipment_item_id',
        'product_id',
        'quantity',
        'value',
        'transparency_codes',
    ];

    protected $casts = [
        'transparency_codes' => 'array',
        'value' => 'decimal:2',
    ];

    /**
     * The unit value this line declares, in its Shipment's currency: its own
     * `value`, set when it was packed by scanning (`manual-ship-contents/01`),
     * else its Shipment item's. Null when neither has one.
     */
    public function declaredUnitValue(): ?float
    {
        $value = $this->value ?? $this->shipmentItem?->value;

        return $value === null ? null : (float) $value;
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return BelongsTo<ShipmentItem, $this>
     */
    public function shipmentItem(): BelongsTo
    {
        return $this->belongsTo(ShipmentItem::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
