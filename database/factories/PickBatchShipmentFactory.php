<?php

namespace Database\Factories;

use App\Models\PickBatch;
use App\Models\PickBatchShipment;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PickBatchShipment>
 */
class PickBatchShipmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pick_batch_id' => PickBatch::factory(),
            'shipment_id' => Shipment::factory(),
            'tote_code' => null,
            'picked_at' => null,
        ];
    }

    public function picked(): static
    {
        return $this->state(fn () => [
            'picked_at' => now(),
        ]);
    }
}
