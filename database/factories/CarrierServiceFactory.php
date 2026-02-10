<?php

namespace Database\Factories;

use App\Models\Carrier;
use App\Models\CarrierService;
use Illuminate\Database\Eloquent\Factories\Factory;

class CarrierServiceFactory extends Factory
{
    protected $model = CarrierService::class;

    public function definition(): array
    {
        return [
            'carrier_id' => Carrier::factory(),
            'name' => fake()->words(3, true),
            'service_code' => fake()->regexify('[A-Z_]{10,20}'),
            'active' => true,
        ];
    }

    public function uspsGroundAdvantage(): static
    {
        return $this->state(fn () => [
            'name' => 'USPS Ground Advantage',
            'service_code' => 'USPS_GROUND_ADVANTAGE',
        ]);
    }

    public function uspsPriority(): static
    {
        return $this->state(fn () => [
            'name' => 'Priority Mail',
            'service_code' => 'PRIORITY_MAIL',
        ]);
    }

    public function fedexGround(): static
    {
        return $this->state(fn () => [
            'name' => 'FedEx Ground',
            'service_code' => 'FEDEX_GROUND',
        ]);
    }

    public function fedexExpress(): static
    {
        return $this->state(fn () => [
            'name' => 'FedEx Express Saver',
            'service_code' => 'FEDEX_EXPRESS_SAVER',
        ]);
    }

    public function upsGround(): static
    {
        return $this->state(fn () => [
            'name' => 'UPS Ground',
            'service_code' => '03',
        ]);
    }

    public function upsNextDay(): static
    {
        return $this->state(fn () => [
            'name' => 'UPS Next Day Air',
            'service_code' => '01',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
