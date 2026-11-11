<?php

namespace App\Models;

use App\Enums\OtdrProtectedOrders;
use App\Enums\PostageSourceKind;
use App\Enums\UnlistedServices;
use App\Policies\ShippingMethodPostageSourcePolicy;
use Database\Factories\ShippingMethodPostageSourceFactory;
use DomainException;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One kind of postage source a shipping method allows — ADR-0006 decision 5,
 * option O, `carrier-catalog-reset/09`.
 *
 * A row means the source may sell for the method, and no row means it may not.
 * Every method is given a `direct` row when it is created, which is "direct on
 * by default"; deleting it turns direct off. Admin-only
 * ({@see ShippingMethodPostageSourcePolicy}), while a Manager
 * edits the method's services and rules, which pick within what these allow.
 *
 * @property int $shipping_method_id
 * @property PostageSourceKind $source_kind
 * @property UnlistedServices $unlisted_services
 * @property Collection<int, OtdrProtectedOrders>|null $otdr_protection_orders The Amazon orders automation buys only OTDR-protected offers for; Amazon rows only
 */
class ShippingMethodPostageSource extends Model
{
    /** @use HasFactory<ShippingMethodPostageSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'shipping_method_id',
        'source_kind',
        'unlisted_services',
        'otdr_protection_orders',
    ];

    protected $attributes = [
        'unlisted_services' => 'none',
    ];

    protected function casts(): array
    {
        return [
            'source_kind' => PostageSourceKind::class,
            'unlisted_services' => UnlistedServices::class,
            'otdr_protection_orders' => AsEnumCollection::of(OtdrProtectedOrders::class),
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ShippingMethodPostageSource $row): void {
            $row->assertAccepted();
        });
    }

    /**
     * @return BelongsTo<ShippingMethod, $this>
     */
    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function allowsUnlistedServices(): bool
    {
        return $this->unlisted_services === UnlistedServices::Any;
    }

    /**
     * Whether automation must buy an OTDR-protected offer for this Amazon
     * order. An order that counts as more than one kind is covered if any of
     * them is ticked.
     */
    public function requiresOtdrProtectionFor(Shipment $shipment): bool
    {
        $required = $this->otdr_protection_orders ?? collect();

        return collect(OtdrProtectedOrders::forShipment($shipment))
            ->contains(fn (OtdrProtectedOrders $kind): bool => $required->contains($kind));
    }

    /**
     * Refuse a value this kind does not accept, such as a `direct` row that
     * would sell beyond the method's services.
     *
     * @throws DomainException
     */
    private function assertAccepted(): void
    {
        if (! in_array($this->unlisted_services, $this->source_kind->acceptedUnlistedServices(), true)) {
            throw new DomainException("{$this->source_kind->label()} cannot sell services the shipping method does not list.");
        }

        if ($this->source_kind !== PostageSourceKind::Amazon && filled($this->otdr_protection_orders?->all())) {
            throw new DomainException("Only Amazon Buy Shipping offers can be OTDR-protected, not {$this->source_kind->label()}.");
        }
    }
}
