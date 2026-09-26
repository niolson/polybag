<?php

namespace App\Models;

use App\Exceptions\SystemCarrierLockedException;
use App\Services\CacheService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Carrier extends Model
{
    use HasFactory;

    /**
     * Names of the direct carriers. A system carrier's name is its key: the
     * registry, the seeders, alias matching and ship dates find it by name.
     */
    public const USPS = 'USPS';

    public const UPS = 'UPS';

    public const FEDEX = 'FedEx';

    /** A carrier Shopify sells, with no integration of ours. */
    public const DHL_EXPRESS = 'DHL Express';

    /** Amazon's own network, sold today through Amazon Buy Shipping. */
    public const AMAZON_SHIPPING = 'Amazon Shipping';

    /** A carrier Amazon Buy Shipping sells, with no integration of ours. */
    public const ONTRAC = 'OnTrac';

    protected static function booted(): void
    {
        static::updating(function (Carrier $carrier): void {
            if ($carrier->getOriginal('is_system') && $carrier->isDirty('name')) {
                throw SystemCarrierLockedException::rename($carrier->getOriginal('name'));
            }
        });

        static::deleting(function (Carrier $carrier): void {
            if ($carrier->is_system) {
                throw SystemCarrierLockedException::delete($carrier->name);
            }
        });

        // Clear carrier services cache when carrier changes (affects active services)
        static::saved(fn () => app(CacheService::class)->clearCarrierServicesCache());
        static::deleted(fn () => app(CacheService::class)->clearCarrierServicesCache());
    }

    /**
     * `is_system` is set only by the seeders and the migration that added it,
     * never from a request.
     */
    protected $fillable = [
        'name',
        'display_name',
        'pickup_cutoff_hour',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'pickup_cutoff_hour' => 'integer',
            'active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CarrierService, $this>
     */
    public function carrierServices(): HasMany
    {
        return $this->hasMany(CarrierService::class);
    }

    /**
     * @return HasMany<CarrierAccount, $this>
     */
    public function carrierAccounts(): HasMany
    {
        return $this->hasMany(CarrierAccount::class);
    }

    /**
     * Raw carrier names that normalize to this carrier identity.
     *
     * @return HasMany<CarrierAlias, $this>
     */
    public function carrierAliases(): HasMany
    {
        return $this->hasMany(CarrierAlias::class);
    }

    /**
     * Packages that permanently snapshot this carrier identity.
     *
     * @return HasMany<Package, $this>
     */
    public function normalizedPackages(): HasMany
    {
        return $this->hasMany(Package::class, 'normalized_carrier_id');
    }

    /**
     * @return BelongsToMany<Location, $this>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'carrier_location')
            ->withPivot('pickup_days', 'last_end_of_day_at')
            ->withTimestamps();
    }

    /**
     * What the UI shows for this carrier. `name` stays the key for everything
     * that leaves the app or matches incoming text.
     */
    public function label(): string
    {
        return filled($this->display_name) ? $this->display_name : $this->name;
    }

    /**
     * What the UI shows for a carrier known only by its name, such as the
     * free-text `packages.carrier`. Text that names no carrier row, like a
     * carrier of record we hold no row for, is shown as it is.
     */
    public static function labelForName(string $name): string
    {
        // One query per request, however many rows a table renders.
        $displayNames = once(fn (): array => static::query()
            ->whereNotNull('display_name')
            ->pluck('display_name', 'name')
            ->all());

        return filled($displayNames[$name] ?? null) ? $displayNames[$name] : $name;
    }

    /**
     * Find or create a seeded carrier by its fixed name and mark it system.
     * A custom carrier an operator made under the same name is adopted, keeping
     * its id, services and display name.
     *
     * @param  array<string, mixed>  $attributes  written only when the row is created
     */
    public static function seedSystem(string $name, array $attributes = []): self
    {
        $carrier = static::firstOrCreate(['name' => $name], $attributes);

        if (! $carrier->is_system) {
            $carrier->forceFill(['is_system' => true])->save();
        }

        return $carrier;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Return the public URL for this carrier's logo, or null if no logo file exists.
     */
    public function logoUrl(): ?string
    {
        return static::logoUrlForName($this->name);
    }

    /**
     * Return the public URL for a carrier logo by name, or null if no logo file exists.
     */
    public static function logoUrlForName(string $name): ?string
    {
        $slug = strtolower(str_replace(' ', '-', $name));
        $path = public_path("images/{$slug}-logo.svg");

        return file_exists($path) ? asset("images/{$slug}-logo.svg") : null;
    }
}
