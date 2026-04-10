<?php

namespace App\Models;

use App\Enums\SpecialServiceSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageSpecialService extends Model
{
    protected $table = 'package_special_services';

    protected $fillable = [
        'package_id',
        'special_service_id',
        'source',
        'source_reference',
        'config',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => SpecialServiceSource::class,
            'config' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function specialService(): BelongsTo
    {
        return $this->belongsTo(SpecialService::class);
    }
}
