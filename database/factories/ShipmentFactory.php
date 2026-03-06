<?php

namespace Database\Factories;

use App\Enums\Deliverability;
use App\Models\Channel;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'shipment_reference' => fake()->unique()->regexify('[A-Z]{2}[0-9]{8}'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'company' => fake()->optional()->company(),
            'address1' => fake()->streetAddress(),
            'address2' => fake()->optional()->secondaryAddress(),
            'city' => fake()->city(),
            'state_or_province' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country' => 'US',
            'phone' => fake()->optional()->phoneNumber(),
            'phone_extension' => null,
            'email' => fake()->optional()->email(),
            'value' => fake()->randomFloat(2, 10, 500),
            'checked' => false,
            'deliverability' => null,
            'shipping_method_id' => ShippingMethod::factory(),
            'channel_reference' => null,
            'channel_id' => Channel::factory(),
            'status' => 'open',
        ];
    }

    public function validated(): static
    {
        return $this->state(fn (array $attributes) => [
            'checked' => true,
            'deliverability' => Deliverability::Yes,
            'validation_message' => 'Address confirmed deliverable',
            'validated_address1' => $attributes['address1'],
            'validated_city' => $attributes['city'],
            'validated_state_or_province' => $attributes['state_or_province'],
            'validated_postal_code' => $attributes['postal_code'],
            'validated_country' => $attributes['country'],
        ]);
    }

    public function undeliverable(): static
    {
        return $this->state(fn () => [
            'checked' => true,
            'deliverability' => Deliverability::No,
            'validation_message' => 'Address found but not confirmed as deliverable',
        ]);
    }

    public function maybeDeliverable(): static
    {
        return $this->state(fn () => [
            'checked' => true,
            'deliverability' => Deliverability::Maybe,
            'validation_message' => 'Primary address confirmed, secondary number missing',
        ]);
    }

    public function shipped(): static
    {
        return $this->state(fn () => [
            'status' => 'shipped',
        ]);
    }

    /**
     * Create an international shipment (non-US).
     */
    public function international(): static
    {
        return $this->state(fn () => [
            'country' => fake()->randomElement(['CA', 'MX', 'GB', 'DE', 'FR', 'AU', 'JP']),
            'state_or_province' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
        ]);
    }

    /**
     * Create a shipment with residential flag set.
     */
    public function residential(): static
    {
        return $this->state(fn () => [
            'residential' => true,
            'validated_residential' => true,
        ]);
    }

    /**
     * Create a shipment with commercial flag set.
     */
    public function commercial(): static
    {
        return $this->state(fn () => [
            'residential' => false,
            'validated_residential' => false,
            'company' => fake()->company(),
        ]);
    }

    /**
     * Create a shipment without a shipping method (for testing rate shopping).
     */
    public function withoutShippingMethod(): static
    {
        return $this->state(fn () => [
            'shipping_method_id' => null,
        ]);
    }
}
