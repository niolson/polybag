<?php

namespace App\Models;

use Database\Factories\DataSourceLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSourceLocation extends Model
{
    /** @use HasFactory<DataSourceLocationFactory> */
    use HasFactory;

    protected $fillable = [
        'data_source_id',
        'external_id',
        'external_code',
        'name',
        'address',
        'is_active',
        'location_id',
        'ignored_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'is_active' => 'boolean',
            'ignored_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Mapping, unmapping or ignoring a location changes what an import
        // keeps without changing anything at the source, so a search for what
        // changed there would miss it. A run that started no later than this
        // read with the old mapping, and the next reads every open record
        // instead (`order-reconciliation/11`).
        static::updated(function (DataSourceLocation $sourceLocation): void {
            if ($sourceLocation->wasChanged(['location_id', 'ignored_at'])) {
                DataSource::query()
                    ->whereKey($sourceLocation->data_source_id)
                    ->update(['locations_remapped_at' => now()]);
            }
        });

        static::saved(function (DataSourceLocation $sourceLocation): void {
            if (! $sourceLocation->wasChanged('location_id') || $sourceLocation->location_id === null) {
                return;
            }

            $sourceLocation->shipments()
                ->where('status', 'open')
                ->whereDoesntHave('packages')
                ->update(['location_id' => $sourceLocation->location_id]);
        });
    }

    public function isMapped(): bool
    {
        return $this->location_id !== null && $this->ignored_at === null;
    }

    public function isIgnored(): bool
    {
        return $this->ignored_at !== null;
    }

    public function isUnmapped(): bool
    {
        return ! $this->isMapped() && ! $this->isIgnored();
    }

    /** @return BelongsTo<DataSource, $this> */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<Shipment, $this> */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
