<?php

namespace Database\Factories\Sys;

use App\Models\Sys\Kv;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Kv>
 */
class KvFactory extends Factory
{
    protected $model = Kv::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->word(),
            'value' => ['data' => fake()->word()],
        ];
    }
}
