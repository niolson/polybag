<?php

namespace App\Models;

use Database\Factories\CarrierAliasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CarrierAlias extends Model
{
    /** @use HasFactory<CarrierAliasFactory> */
    use HasFactory;

    protected $fillable = [
        'carrier_id',
        'alias',
    ];

    protected static function booted(): void
    {
        static::saving(function (CarrierAlias $carrierAlias): void {
            $carrierAlias->lookup_key = static::lookupKey($carrierAlias->alias);

            $message = static::conflictMessage(
                $carrierAlias->lookup_key,
                (int) $carrierAlias->carrier_id,
                $carrierAlias->exists ? (int) $carrierAlias->getKey() : null,
            );

            if ($message !== null) {
                throw ValidationException::withMessages(['alias' => $message]);
            }
        });
    }

    /**
     * The key a carrier name is matched on.
     *
     * Trademark signs are folded out because carriers print them in the names
     * they send — Shopify returns UPS as `UPS®` — and they are decoration
     * rather than identity. Folding them here rather than adding an alias row
     * per marked name is the difference between fixing one string and fixing
     * the class of them. Nothing else is stripped: punctuation may yet be the
     * only thing separating two carriers we have not met.
     */
    public static function lookupKey(?string $carrierName): string
    {
        $unmarked = str_replace(['®', '™', '℠'], ' ', $carrierName ?? '');

        return Str::lower(Str::squish($unmarked));
    }

    public static function conflictMessage(string $lookupKey, int $carrierId, ?int $ignoreId = null): ?string
    {
        $aliasExists = static::query()
            ->where('lookup_key', $lookupKey)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($aliasExists) {
            return 'This carrier alias already exists.';
        }

        $canonicalCarrier = Carrier::query()
            ->get()
            ->first(fn (Carrier $carrier): bool => static::lookupKey($carrier->name) === $lookupKey);

        if ($canonicalCarrier && $canonicalCarrier->id !== $carrierId) {
            return "This is the canonical name of {$canonicalCarrier->name}.";
        }

        return null;
    }

    /**
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }
}
