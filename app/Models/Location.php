<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'company',
        'first_name',
        'last_name',
        'address1',
        'address2',
        'city',
        'state_or_province',
        'postal_code',
        'country',
        'phone',
        'is_default',
        'active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'active' => 'boolean',
    ];

    /**
     * Get the default location, cached for the request lifecycle.
     */
    public static function getDefault(): ?self
    {
        return Cache::remember('location_default', 3600, function () {
            return static::where('is_default', true)->first();
        });
    }

    /**
     * Clear the default location cache.
     */
    public static function clearDefaultCache(): void
    {
        Cache::forget('location_default');
    }

    /**
     * When setting this location as default, unset all others.
     */
    protected static function booted(): void
    {
        static::saving(function (Location $location) {
            if ($location->is_default && $location->isDirty('is_default')) {
                static::where('id', '!=', $location->id ?? 0)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });

        static::saved(function () {
            static::clearDefaultCache();
        });

        static::deleted(function () {
            static::clearDefaultCache();
        });
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }
}
