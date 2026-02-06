<?php

namespace Database\Factories;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'channel_reference' => fake()->unique()->slug(2),
            'icon' => null,
        ];
    }
}
