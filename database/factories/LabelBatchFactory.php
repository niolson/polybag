<?php

namespace Database\Factories;

use App\Enums\LabelBatchStatus;
use App\Models\BoxSize;
use App\Models\LabelBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LabelBatchFactory extends Factory
{
    protected $model = LabelBatch::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'box_size_id' => BoxSize::factory(),
            'label_format' => 'pdf',
            'label_dpi' => null,
            'status' => LabelBatchStatus::Pending,
            'total_shipments' => 0,
            'successful_shipments' => 0,
            'failed_shipments' => 0,
            'total_cost' => 0,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => LabelBatchStatus::Processing,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => LabelBatchStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => LabelBatchStatus::Failed,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }
}
