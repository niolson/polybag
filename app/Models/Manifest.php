<?php

namespace App\Models;

use Database\Factories\ManifestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Manifest extends Model
{
    /** @use HasFactory<ManifestFactory> */
    use HasFactory;

    protected $fillable = [
        'carrier',
        'manifest_number',
        'image',
        'manifest_date',
        'package_count',
    ];

    protected function casts(): array
    {
        return [
            'manifest_date' => 'date',
            'package_count' => 'integer',
        ];
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }
}
