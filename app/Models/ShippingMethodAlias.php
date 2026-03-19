<?php

namespace App\Models;

use Database\Factories\ShippingMethodAliasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingMethodAlias extends Model
{
    /** @use HasFactory<ShippingMethodAliasFactory> */
    use HasFactory;

    protected $fillable = [
        'reference',
        'shipping_method_id',
    ];

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }
}
