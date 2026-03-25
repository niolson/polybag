<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'icon',
        'active',
        'pii_retention_days',
    ];

    protected $casts = [
        'active' => 'boolean',
        'pii_retention_days' => 'integer',
    ];

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ChannelAlias::class);
    }
}
