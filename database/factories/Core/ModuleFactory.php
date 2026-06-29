<?php

namespace Database\Factories\Core;

use App\Models\Core\Module;
use App\Models\Core\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Module>
 */
class ModuleFactory extends Factory
{
    protected $model = Module::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'module_key' => fake()->unique()->word(),
            'is_enabled' => false,
        ];
    }
}
