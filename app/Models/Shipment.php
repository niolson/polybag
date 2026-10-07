<?php

namespace App\Models;

use App\Enums\AddressValidationOutcome;
use App\Enums\AddressValidator;
use App\Enums\Deliverability;
use App\Enums\PackageStatus;
use App\Enums\PackSlipState;
use App\Enums\PickBatchStatus;
use App\Enums\PickingStatus;
use App\Enums\ShipmentStatus;
use App\Enums\ValidationTrigger;
use App\Models\Concerns\HasDefaultClient;
use App\Services\AddressReferenceService;
use App\Services\AddressValidationService;
use App\Services\PhoneParserService;
use App\Services\PickBatchService;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Searchable;

class Shipment extends Model
{
    use HasDefaultClient, HasFactory, Searchable;

    /**
     * The address a validator checks. A change to it discards the validation
     * result, since rating and labels prefer the validated address over it.
     *
     * @var list<string>
     */
    public const ADDRESS_FIELDS = [
        'address1',
        'address2',
        'city',
        'state_or_province',
        'postal_code',
        'country',
    ];

    /**
     * Everything a validation result consists of, as a fresh Shipment has it.
     * `validation_attempts` is deliberately absent: it bounds scheduled
     * validation across address changes.
     *
     * @var array<string, mixed>
     */
    public const UNVALIDATED = [
        'checked' => false,
        'deliverability' => Deliverability::NotChecked,
        'validation_message' => null,
        'validation_source' => null,
        'validation_attempted_at' => null,
        ...self::NO_VALIDATED_ADDRESS,
    ];

    /**
     * A validator's correction, which `AddressData::fromShipment()` prefers
     * over the entered address for rates and Labels.
     */
    public const NO_VALIDATED_ADDRESS = [
        'validated_company' => null,
        'validated_address1' => null,
        'validated_address2' => null,
        'validated_city' => null,
        'validated_state_or_province' => null,
        'validated_postal_code' => null,
        'validated_country' => null,
        'validated_residential' => null,
        'validated_carrier_route' => null,
    ];

    protected $fillable = [
        'client_id',
        'location_id',
        'shipment_reference',
        'source_record_id',
        'source_checksum',
        'first_name',
        'last_name',
        'company',
        'address1',
        'address2',
        'city',
        'state_or_province',
        'postal_code',
        'country',
        'phone',
        'phone_e164',
        'phone_extension',
        'email',
        'value',
        'residential',
        'checked',
        'deliverability',
        'validation_message',
        'validation_source',
        'validation_attempted_at',
        'validation_attempts',
        'validated_company',
        'validated_address1',
        'validated_address2',
        'validated_city',
        'validated_state_or_province',
        'validated_postal_code',
        'validated_country',
        'validated_residential',
        'shipping_method_reference',
        'shipping_method_id',
        'channel_reference',
        'channel_id',
        'data_source_id',
        'data_source_location_id',
        'status',
        'picking_status',
        'deliver_by',
        'metadata',
    ];

    protected $casts = [
        'checked' => 'boolean',
        'validation_attempted_at' => 'datetime',
        'validation_attempts' => 'integer',
        'residential' => 'boolean',
        'validated_residential' => 'boolean',
        'value' => 'decimal:2',
        'deliverability' => Deliverability::class,
        'validation_source' => AddressValidator::class,
        'status' => ShipmentStatus::class,
        'picking_status' => PickingStatus::class,
        'deliver_by' => 'date',
        'metadata' => 'array',
        'items_version' => 'integer',
        'pack_slip_items_version' => 'integer',
        'pack_slip_printed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Shipment $shipment): void {
            $addressReference = app(AddressReferenceService::class);

            $shipment->country = $addressReference->normalizeCountry($shipment->country) ?? ($shipment->country ? strtoupper(trim($shipment->country)) : null);
            $shipment->state_or_province = $addressReference->normalizeSubdivision($shipment->country, $shipment->state_or_province);

            $shipment->validated_country = $addressReference->normalizeCountry($shipment->validated_country) ?? ($shipment->validated_country ? strtoupper(trim($shipment->validated_country)) : null);
            $shipment->validated_state_or_province = $addressReference->normalizeSubdivision($shipment->validated_country, $shipment->validated_state_or_province);

            $shipment->phone = filled($shipment->phone) ? trim($shipment->phone) : null;
            $shipment->phone_extension = filled($shipment->phone_extension) ? trim($shipment->phone_extension) : null;

            if ($shipment->phone) {
                $parsedPhone = PhoneParserService::parse($shipment->phone, $shipment->country);
                $shipment->phone_e164 = $parsedPhone->e164;

                if (blank($shipment->phone_extension) && $parsedPhone->extension !== null) {
                    $shipment->phone_extension = $parsedPhone->extension;
                }
            } else {
                $shipment->phone_e164 = null;
                $shipment->phone_extension = null;
            }

            if (! $shipment->exists || $shipment->isDirty('validation_attempted_at')) {
                return;
            }

            $changes = self::addressChanges($shipment->getOriginal(), $shipment->getAttributes());

            if ($changes !== null) {
                // The old result describes an address the Shipment no longer has.
                $shipment->forceFill(self::UNVALIDATED);
                self::stampAddressChanged($shipment->id, $changes);
            } elseif ($shipment->isDirty('shipping_method_id')) {
                // The method decides which carrier validators may run, so one
                // the old method ruled out deserves its turn on the schedule.
                $shipment->validation_attempted_at = null;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[SearchUsingPrefix(['shipment_reference'])]
    public function toSearchableArray(): array
    {
        return [
            'shipment_reference' => $this->shipment_reference,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'address1' => $this->address1,
            'city' => $this->city,
            'email' => $this->email,
        ];
    }

    /**
     * Recalculate and persist the status based on package status.
     */
    public function updateShippedStatus(): void
    {
        // Don't change status of voided shipments
        if ($this->status === ShipmentStatus::Void) {
            return;
        }

        $hasShippedPackage = $this->packages()->where('status', PackageStatus::Shipped)->exists();

        if (! $hasShippedPackage) {
            $this->update(['status' => ShipmentStatus::Open]);

            return;
        }

        if (! app(SettingsService::class)->get('packing_validation_enabled', true)) {
            $this->markShipped();

            return;
        }

        $shippedPackageIds = $this->packages()->where('status', PackageStatus::Shipped)->pluck('id');

        $packedQuantities = PackageItem::whereIn('package_id', $shippedPackageIds)
            ->selectRaw('shipment_item_id, SUM(quantity) as total_packed')
            ->groupBy('shipment_item_id')
            ->pluck('total_packed', 'shipment_item_id');

        $allItemsShipped = $this->shipmentItems->every(function (ShipmentItem $item) use ($packedQuantities): bool {
            return ($packedQuantities[$item->id] ?? 0) >= $item->quantity;
        });

        if ($allItemsShipped) {
            $this->markShipped();
        } else {
            $this->update(['status' => ShipmentStatus::Open]);
        }
    }

    private function markShipped(): void
    {
        $this->update(['status' => ShipmentStatus::Shipped]);

        app(PickBatchService::class)->removeFromActiveBatches($this);
    }

    /**
     * Whether picking is required and this shipment has not been picked yet,
     * blocking packing and batch shipping.
     */
    public function isBlockedByPicking(): bool
    {
        if ($this->picking_status === PickingStatus::Picked) {
            return false;
        }

        $settings = app(SettingsService::class);

        return (bool) $settings->get('picking_enabled', false)
            && (bool) $settings->get('require_picking_before_shipping', false);
    }

    /**
     * Whether this shipment has no shipping method, so nothing may be bought
     * for it (`carrier-catalog-reset/16`). It can still be packed.
     *
     * Worked out, never stored: a stored state would have to follow every
     * method assignment and every alias that resolves an import.
     */
    public function needsShippingMethod(): bool
    {
        return $this->shipping_method_id === null;
    }

    /**
     * Open shipments with no shipping method: the *Needs shipping method* view
     * on the Shipments list and the Exceptions widget.
     */
    public function scopeNeedingShippingMethod(Builder $query): Builder
    {
        return $query->where('status', ShipmentStatus::Open)->whereNull('shipping_method_id');
    }

    /**
     * Shipments whose latest pack slip is in any of the given states.
     */
    public function scopeWithPackSlipState(Builder $query, PackSlipState ...$states): Builder
    {
        return $query->where(function (Builder $query) use ($states): void {
            foreach ($states as $state) {
                $query->orWhere(fn (Builder $query): Builder => match ($state) {
                    PackSlipState::NotPrinted => $query->whereNull('shipments.pack_slip_items_version'),
                    PackSlipState::ChangedSincePrinted => $query
                        ->whereNotNull('shipments.pack_slip_items_version')
                        ->whereColumn('shipments.items_version', '>', 'shipments.pack_slip_items_version'),
                    PackSlipState::Printed => $query
                        ->whereNotNull('shipments.pack_slip_items_version')
                        ->whereColumn('shipments.items_version', '<=', 'shipments.pack_slip_items_version'),
                });
            }
        });
    }

    /**
     * Whether Amazon fulfills this order itself (FBA) rather than the seller.
     *
     * An FBA order is picked, packed and shipped from an Amazon warehouse, so
     * packing one here produces a duplicate physical shipment and a
     * `confirmShipment` call Amazon rejects. These are excluded from import by
     * default; this covers the ones that arrive when a source opts back in.
     */
    public function isAmazonFulfilled(): bool
    {
        return ($this->metadata['amazon_fulfilled_by'] ?? null) === AmazonSource::FULFILLED_BY_AMAZON;
    }

    /**
     * Validate the shipment's address using USPS API.
     */
    /**
     * Whether the address in `$after` differs from `$before`, ignoring case
     * and whitespace: a source that reformats an address on every import must
     * not discard its validation each time. Only fields present in `$after`
     * are compared.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function addressChanged(array $before, array $after): bool
    {
        return self::addressChanges($before, $after) !== null;
    }

    /**
     * Which parts of the address differ, ignoring case and spacing, or null
     * when none does. Only fields present in `$after` count, so a partial
     * update compares what it sets. A new country changes every part.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{street_changed: bool, unit_changed: bool, locality_changed: bool, postcode_changed: bool}|null
     */
    public static function addressChanges(array $before, array $after): ?array
    {
        $normalize = fn (mixed $value): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '');
        $changed = fn (string $field): bool => array_key_exists($field, $after)
            && $normalize($after[$field]) !== $normalize($before[$field] ?? null);

        $country = $changed('country');
        $changes = [
            'street_changed' => $country || $changed('address1'),
            'unit_changed' => $country || $changed('address2'),
            'locality_changed' => $country || $changed('city') || $changed('state_or_province'),
            'postcode_changed' => $country || $changed('postal_code'),
        ];

        return in_array(true, $changes, true) ? $changes : null;
    }

    /**
     * Record on the Shipment's validator answers that its address changed
     * after validation, and which parts — evidence that an answer was wrong,
     * which outlives the address once PII is purged. An answer keeps its
     * first stamp.
     *
     * @param  array{street_changed: bool, unit_changed: bool, locality_changed: bool, postcode_changed: bool}  $changes
     */
    public static function stampAddressChanged(int $shipmentId, array $changes): void
    {
        AddressValidationAnswer::where('shipment_id', $shipmentId)
            ->whereNull('address_changed_at')
            ->update(['address_changed_at' => now(), ...$changes]);
    }

    public function validateAddress(ValidationTrigger $trigger = ValidationTrigger::Manual): AddressValidationOutcome
    {
        return app(AddressValidationService::class)->validate($this, $trigger);
    }

    /**
     * @return HasMany<AddressValidationAnswer, $this>
     */
    public function validationAnswers(): HasMany
    {
        return $this->hasMany(AddressValidationAnswer::class);
    }

    /**
     * Calculate the deliver-by deadline for this shipment.
     *
     * Priority: explicit deliver_by date > calculated from commitment_days > null.
     */
    public function getDeliverByDate(): ?Carbon
    {
        // 1. Explicit deliver_by date on the shipment
        if ($this->deliver_by) {
            return $this->deliver_by;
        }

        // 2. Calculated from ShippingMethod.commitment_days
        $commitmentDays = $this->shippingMethod?->commitment_days;
        if ($commitmentDays) {
            $date = Carbon::today();
            $added = 0;
            while ($added < $commitmentDays) {
                $date->addDay();
                if (! $date->isWeekend()) {
                    $added++;
                }
            }

            return $date;
        }

        // 3. No deadline
        return null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function packSlipPrintedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pack_slip_printed_by_user_id');
    }

    /**
     * Whether a pack slip has ever been recorded as printed for this Shipment.
     */
    public function hasPrintedPackSlip(): bool
    {
        return $this->pack_slip_items_version !== null;
    }

    /**
     * Whether the items have changed since the latest recorded slip was drawn.
     */
    public function packSlipIsOutOfDate(): bool
    {
        return $this->hasPrintedPackSlip() && $this->items_version > $this->pack_slip_items_version;
    }

    /**
     * Record that these Shipments' items changed, which makes any slip already
     * printed for them out of date. A database increment, so concurrent writers
     * never lose a change. Call it in the same transaction as the item write.
     */
    public static function incrementItemsVersion(int ...$shipmentIds): void
    {
        static::query()->whereKey($shipmentIds)->increment('items_version');
    }

    /**
     * The in-progress pick batch this Shipment is in, if any.
     */
    public function activePickBatch(): ?PickBatch
    {
        return once(fn (): ?PickBatch => PickBatch::query()
            ->where('status', PickBatchStatus::InProgress)
            ->whereHas('pickBatchShipments', fn (Builder $query) => $query->where('shipment_id', $this->id))
            ->latest('id')
            ->first());
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<ShipmentItem, $this>
     */
    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    /**
     * @return HasMany<Package, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    /**
     * @return BelongsTo<ShippingMethod, $this>
     */
    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<DataSourceLocation, $this> */
    public function dataSourceLocation(): BelongsTo
    {
        return $this->belongsTo(DataSourceLocation::class);
    }

    /**
     * @return HasMany<PickBatchShipment, $this>
     */
    public function pickBatchShipments(): HasMany
    {
        return $this->hasMany(PickBatchShipment::class);
    }
}
