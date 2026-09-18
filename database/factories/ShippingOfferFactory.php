<?php

namespace Database\Factories;

use App\DataTransferObjects\Shipping\RateRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\PostageSource;
use App\Enums\SourceEnvironment;
use App\Models\CarrierAccount;
use App\Models\Package;
use App\Models\ShippingOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingOffer>
 */
class ShippingOfferFactory extends Factory
{
    protected $model = ShippingOffer::class;

    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'postage_source' => PostageSource::PostageDataSource,
            'carrier' => 'UPS',
            'service_code' => 'UPS_PTP_NEXT_DAY_AIR_SAVER',
            'service_name' => 'UPS Next Day Air Saver',
            'price' => fake()->randomFloat(2, 5, 40),
            'currency' => 'USD',
            'rate_metadata' => [],
            'purchase_context' => [
                'rateId' => fake()->sha256(),
                'requestToken' => fake()->sha256(),
            ],
            'environment' => SourceEnvironment::Production,
            'marketplace' => 'ATVPDKIKX0DER',
            'expires_at' => now()->addMinutes(10),
        ];
    }

    /**
     * An offer whose window has closed. Purchasing it must fail closed.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function consumed(): static
    {
        return $this->state(fn (): array => [
            'consumed_at' => now(),
            'purchase_reference' => 'PURCHASE-'.fake()->numerify('########'),
        ]);
    }

    /**
     * Spent, and the source answered no. Settled: nothing to recover.
     */
    public function declined(): static
    {
        return $this->state(fn (): array => [
            'consumed_at' => now(),
            'purchase_reference' => null,
            'purchase_failed_at' => now(),
            'purchase_failure_reason' => 'The carrier rejected the shipment.',
        ]);
    }

    /**
     * Spent with nothing heard back at all: the state that means "ask the
     * source before letting anyone buy again".
     */
    public function awaitingConfirmation(): static
    {
        return $this->state(fn (): array => [
            'consumed_at' => now(),
            'purchase_reference' => null,
            'purchase_failed_at' => null,
        ]);
    }

    /**
     * A direct-carrier rate quoted from a carrier account: no purchase
     * context, because the account buys; a window that closes with the ship
     * day rather than in minutes; and no marketplace. What the rate service
     * issues for every USPS, FedEx or UPS rate (`postage-source-split/14`).
     */
    public function direct(): static
    {
        return $this->state(fn (): array => [
            'postage_source' => PostageSource::CarrierAccount,
            'carrier' => 'USPS',
            'service_code' => 'PRIORITY_MAIL',
            'service_name' => 'Priority Mail',
            'rate_metadata' => [
                'mailClass' => 'PRIORITY_MAIL',
                'processingCategory' => 'MACHINABLE',
                'rateIndicator' => 'SP',
                'destinationEntryFacilityType' => 'NONE',
            ],
            'purchase_context' => null,
            'marketplace' => null,
            'expires_at' => now()->endOfDay(),
        ]);
    }

    /**
     * The offer for exactly this rate — carrier, service, price, metadata and
     * the account it was quoted on, billing identity included — as the rate
     * service would have issued it.
     */
    public function forRate(RateResponse $rate): static
    {
        return $this->state(fn (): array => [
            'carrier' => $rate->carrier,
            'carrier_account_id' => $rate->carrierAccountId,
            'carrier_account_fingerprint' => $rate->carrierAccountId === null
                ? null
                : CarrierAccount::query()->find($rate->carrierAccountId)?->fingerprint(),
            'service_code' => $rate->serviceCode,
            'service_name' => $rate->serviceName,
            'price' => $rate->priceUnknown ? null : $rate->price,
            'currency' => $rate->priceUnknown ? null : 'USD',
            'rate_metadata' => $rate->packagingRequirement->intoRateMetadata($rate->metadata),
        ]);
    }

    /**
     * Bound to the rate request this package produces now, so that an edit to
     * anything the carrier was asked to price before the purchase is refused
     * as `PackageChanged`.
     */
    public function quotedFor(Package $package): static
    {
        return $this->state(fn (): array => [
            'package_id' => $package->id,
            'quote_fingerprint' => RateRequest::fromPackage($package)->fingerprint(),
        ]);
    }

    /**
     * A blind purchase: no price, no service, nothing to expire.
     */
    public function priceless(): static
    {
        return $this->state(fn (): array => [
            'carrier' => 'Shopify',
            'service_code' => null,
            'service_name' => null,
            'price' => null,
            'currency' => null,
            'rate_metadata' => null,
            'purchase_context' => null,
            'expires_at' => null,
            'marketplace' => null,
        ]);
    }
}
