<?php

namespace App\Models;

use App\Enums\SpecialServiceScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SpecialService extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'scope',
        'category',
        'requires_value',
        'config_schema',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'scope' => SpecialServiceScope::class,
            'requires_value' => 'boolean',
            'config_schema' => 'array',
            'active' => 'boolean',
        ];
    }

    public function shippingMethods(): BelongsToMany
    {
        return $this->belongsToMany(ShippingMethod::class)
            ->withPivot(['mode', 'config'])
            ->withTimestamps();
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'package_special_services')
            ->withPivot(['source', 'source_reference', 'config', 'applied_at'])
            ->withTimestamps();
    }
}
