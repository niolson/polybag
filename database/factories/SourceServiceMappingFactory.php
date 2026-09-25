<?php

namespace Database\Factories;

use App\Enums\PostageSourceKind;
use App\Models\CarrierService;
use App\Models\SourceServiceMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceServiceMapping>
 */
class SourceServiceMappingFactory extends Factory
{
    protected $model = SourceServiceMapping::class;

    public function definition(): array
    {
        return [
            'source_kind' => PostageSourceKind::Amazon,
            'external_carrier_id' => 'ONTRAC',
            'external_service_id' => fake()->unique()->bothify('ONTRAC_????_##'),
            'carrier_service_id' => CarrierService::factory(),
        ];
    }

    public function shopify(): static
    {
        return $this->state(fn (): array => [
            'source_kind' => PostageSourceKind::Shopify,
            'external_carrier_id' => 'usps',
            'external_service_id' => fake()->unique()->bothify('usps_????_##'),
        ]);
    }
}
