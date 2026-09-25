<?php

namespace App\Models;

use App\Enums\ShippingRuleAction;
use App\Enums\ShippingRuleSource;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rule names a source and a service — `carrier-catalog-reset/07`.
 *
 * A *Use* rule picks within what the shipment's method allows and grants
 * nothing. An *Exclude* rule matches a source, a carrier, a service, or any
 * combination of them.
 *
 * @property ShippingRuleAction $action
 * @property ShippingRuleSource $source
 * @property int|null $carrier_service_id
 * @property bool $any_service
 * @property int|null $carrier_id
 * @property list<array{type: string, data: array<string, mixed>}>|null $conditions
 */
class ShippingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'name',
        'shipping_method_id',
        'priority',
        'conditions',
        'action',
        'source',
        'carrier_service_id',
        'any_service',
        'carrier_id',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'action' => ShippingRuleAction::class,
            'source' => ShippingRuleSource::class,
            'any_service' => 'boolean',
            'conditions' => 'array',
            'enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ShippingRule $rule): void {
            $rule->assertCoherent();
        });
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<ShippingMethod, $this>
     */
    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    /**
     * @return BelongsTo<CarrierService, $this>
     */
    public function carrierService(): BelongsTo
    {
        return $this->belongsTo(CarrierService::class);
    }

    /**
     * The carrier an *Exclude* rule matches, whether or not the offer's
     * service is mapped.
     *
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('enabled', true)->orderBy('priority');
    }

    /**
     * Rules that name this carrier, or any of its services — every rule that
     * would stop the carrier being deleted.
     *
     * @param  Builder<ShippingRule>  $query
     */
    public function scopeNamingCarrier(Builder $query, Carrier $carrier): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('carrier_id', $carrier->getKey())
            ->orWhereIn('carrier_service_id', $carrier->carrierServices()->select('id')));
    }

    /**
     * How a person finds the rule: "“Never OnTrac” on Standard".
     */
    public function describe(): string
    {
        $method = $this->shippingMethod?->name;

        return $method !== null ? "“{$this->name}” on {$method}" : "“{$this->name}” (every shipping method)";
    }

    /**
     * How a person reads the rule's target: "Direct · UPS Ground".
     */
    public function describeTarget(): string
    {
        $parts = [$this->source->getLabel()];

        if ($this->carrier_id !== null) {
            $parts[] = $this->carrier?->label() ?? 'Unknown carrier';
        }

        $parts[] = $this->any_service
            ? 'Any service'
            : ($this->carrierService ? "{$this->carrierService->carrier->label()} {$this->carrierService->name}" : 'Unknown service');

        return implode(' · ', $parts);
    }

    /**
     * Refuse a rule that says nothing, or two things at once.
     *
     * @throws DomainException
     */
    private function assertCoherent(): void
    {
        if ($this->getAttribute('action') === null || $this->getAttribute('source') === null) {
            throw new DomainException('A shipping rule must name an action and a source.');
        }

        $action = $this->action;
        $source = $this->source;

        if (! $source->allowedFor($action)) {
            throw new DomainException("A {$action->getLabel()} rule cannot name {$source->getLabel()}.");
        }

        if (($this->carrier_service_id !== null) === (bool) $this->any_service) {
            throw new DomainException('A shipping rule must name one service or any service, never both or neither.');
        }

        if ($action === ShippingRuleAction::UseService) {
            if ($this->carrier_id !== null) {
                throw new DomainException('Only an Exclude rule can name a carrier.');
            }

            // Only Amazon Buy Shipping discovers its services per quote, so it
            // is the only source a *Use* rule can leave to choose.
            if ($this->any_service && $source !== ShippingRuleSource::Amazon) {
                throw new DomainException('A Use rule must name a service, except for Amazon Buy Shipping.');
            }

            return;
        }

        // Every field an *Exclude* rule names must match one rate, and a rate
        // has one carrier: a service of another carrier would match nothing.
        if ($this->carrier_id !== null && $this->carrier_service_id !== null
            && (int) CarrierService::whereKey($this->carrier_service_id)->value('carrier_id') !== (int) $this->carrier_id) {
            throw new DomainException('An Exclude rule cannot name a carrier and a service of another carrier.');
        }

        if ($source === ShippingRuleSource::Any && $this->carrier_id === null && $this->any_service) {
            throw new DomainException('An Exclude rule must name a source, a carrier or a service.');
        }
    }
}
