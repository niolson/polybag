<?php

namespace Database\Factories;

use App\Models\ScanFormRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScanFormRequest>
 */
class ScanFormRequestFactory extends Factory
{
    protected $model = ScanFormRequest::class;

    public function definition(): array
    {
        return [
            'request_hash' => hash('sha256', fake()->unique()->uuid()),
            'idempotency_key' => fake()->uuid(),
        ];
    }
}
