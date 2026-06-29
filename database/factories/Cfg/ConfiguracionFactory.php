<?php

namespace Database\Factories\Cfg;

use App\Models\Cfg\Configuracion;
use App\Models\Core\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Configuracion>
 */
class ConfiguracionFactory extends Factory
{
    protected $model = Configuracion::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'key' => fake()->unique()->word(),
            'value' => ['data' => fake()->word()],
        ];
    }
}
