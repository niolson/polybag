<?php

namespace App\Models;

use App\Enums\ScheduleInterval;
use App\Models\Concerns\HasDefaultClient;
use Database\Factories\DataSourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSource extends Model
{
    /** @use HasFactory<DataSourceFactory> */
    use HasDefaultClient, HasFactory;

    /** @var list<string> Keys that belong in the encrypted secret_settings column. */
    public const SECRET_SETTINGS_KEYS = ['oauth_access_token', 'client_id', 'client_secret', 'refresh_token', 'db_password'];

    protected $table = 'data_sources';

    protected $fillable = [
        'client_id',
        'name',
        'source_type',
        'active',
        'import_enabled',
        'global_export',
        'schedule_interval',
        'settings',
        'secret_settings',
    ];

    protected $hidden = [
        'secret_settings',
    ];

    protected $casts = [
        'active' => 'boolean',
        'import_enabled' => 'boolean',
        'global_export' => 'boolean',
        'schedule_interval' => ScheduleInterval::class,
        'settings' => 'array',
        'secret_settings' => 'encrypted:array',
    ];

    /**
     * Connections whose orders are imported: active, with import turned on.
     * Postage bound to the originating connection needs only `active`, so this
     * is for the import paths alone.
     *
     * @param  Builder<DataSource>  $query
     * @return Builder<DataSource>
     */
    public function scopeImporting(Builder $query): Builder
    {
        return $query->where('active', true)->where('import_enabled', true);
    }

    public function importsOrders(): bool
    {
        return $this->active && $this->import_enabled;
    }

    public function secret(string $key): mixed
    {
        return ($this->secret_settings ?? [])[$key] ?? null;
    }

    public function mergeSecret(string $key, mixed $value): void
    {
        $this->secret_settings = array_merge($this->secret_settings ?? [], [$key => $value]);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /** @return HasMany<DataSourceLocation, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(DataSourceLocation::class);
    }
}
