<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ChannelAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelAlias>
 */
class ChannelAliasFactory extends Factory
{
    protected $model = ChannelAlias::class;

    public function definition(): array
    {
        return [
            'reference' => fake()->unique()->slug(2),
            'channel_id' => Channel::factory(),
        ];
    }
}
