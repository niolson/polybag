<?php

namespace Database\Factories;

use App\Enums\PickBatchStatus;
use App\Models\PickBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PickBatch>
 */
class PickBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => PickBatchStatus::InProgress,
            'total_shipments' => 0,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => PickBatchStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => PickBatchStatus::Cancelled,
        ]);
    }
}
