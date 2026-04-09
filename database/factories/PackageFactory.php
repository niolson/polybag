<?php

namespace Database\Factories;

use App\Models\BoxSize;
use App\Models\Manifest;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        return [
            'shipment_id' => Shipment::factory(),
            'box_size_id' => fake()->optional()->randomElement([BoxSize::factory(), null]),
            'tracking_number' => null,
            'carrier' => null,
            'service' => null,
            'metadata' => null,
            'label_data' => null,
            'weight' => fake()->randomFloat(2, 0.5, 50),
            'height' => fake()->randomFloat(2, 2, 20),
            'width' => fake()->randomFloat(2, 2, 20),
            'length' => fake()->randomFloat(2, 2, 20),
            'cost' => null,
            'weight_mismatch' => false,
            'status' => 'unshipped',
            'shipped_at' => null,
            'exported' => false,
            'tracking_status' => null,
            'tracking_updated_at' => null,
            'delivered_at' => null,
            'tracking_details' => null,
            'tracking_checked_at' => null,
        ];
    }

    public function shipped(): static
    {
        return $this->state(fn () => [
            'tracking_number' => fake()->regexify('[0-9]{20}'),
            'carrier' => fake()->randomElement(['USPS', 'FedEx']),
            'service' => 'Ground',
            'cost' => fake()->randomFloat(2, 5, 50),
            'label_data' => base64_encode('mock-label-pdf'),
            'label_orientation' => 'portrait',
            'status' => 'shipped',
            'shipped_at' => now(),
            'ship_date' => today(),
            'shipped_by_user_id' => User::factory(),
        ]);
    }

    /**
     * Create a package with label data but not yet marked shipped.
     * Useful for testing label reprint scenarios.
     */
    public function withLabel(): static
    {
        return $this->state(fn () => [
            'label_data' => base64_encode('mock-label-pdf'),
            'label_orientation' => 'portrait',
        ]);
    }

    /**
     * Create a USPS shipped package.
     */
    public function usps(): static
    {
        return $this->shipped()->state(fn () => [
            'carrier' => 'USPS',
            'service' => 'Priority Mail',
            'tracking_number' => fake()->regexify('94[0-9]{20}'),
        ]);
    }

    /**
     * Create a FedEx shipped package.
     */
    public function fedex(): static
    {
        return $this->shipped()->state(fn () => [
            'carrier' => 'FedEx',
            'service' => 'FedEx Ground',
            'tracking_number' => fake()->regexify('[0-9]{12}'),
        ]);
    }

    /**
     * Create a package with a specific box size.
     */
    public function withBoxSize(): static
    {
        return $this->state(fn () => [
            'box_size_id' => BoxSize::factory(),
        ]);
    }

    /**
     * Create an exported package.
     */
    public function exported(): static
    {
        return $this->shipped()->state(fn () => [
            'exported' => true,
        ]);
    }

    /**
     * Create a package with manifest.
     */
    public function manifested(): static
    {
        return $this->shipped()->state(fn () => [
            'manifest_id' => Manifest::factory(),
        ]);
    }
}
