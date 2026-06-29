<?php

namespace Database\Factories\Cfg;

use App\Models\Cfg\CatalogoAuxiliar;
use App\Models\Core\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogoAuxiliar>
 */
class CatalogoAuxiliarFactory extends Factory
{
    protected $model = CatalogoAuxiliar::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'catalog' => fake()->word(),
            'code' => fake()->unique()->bothify('??-###'),
            'label' => fake()->words(2, true),
            'meta' => null,
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
