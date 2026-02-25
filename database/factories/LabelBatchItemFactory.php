<?php

namespace Database\Factories;

use App\Enums\LabelBatchItemStatus;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

class LabelBatchItemFactory extends Factory
{
    protected $model = LabelBatchItem::class;

    public function definition(): array
    {
        return [
            'label_batch_id' => LabelBatch::factory(),
            'shipment_id' => Shipment::factory(),
            'package_id' => null,
            'status' => LabelBatchItemStatus::Pending,
        ];
    }

    public function success(): static
    {
        return $this->state(fn () => [
            'status' => LabelBatchItemStatus::Success,
            'tracking_number' => fake()->regexify('[0-9]{22}'),
            'carrier' => 'USPS',
            'service' => 'USPS_GROUND_ADVANTAGE',
            'cost' => fake()->randomFloat(2, 3, 15),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => LabelBatchItemStatus::Failed,
            'error_message' => 'Failed to generate label',
        ]);
    }
}
