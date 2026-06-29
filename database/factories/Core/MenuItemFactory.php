<?php

namespace Database\Factories\Core;

use App\Models\Core\MenuItem;
use App\Models\Core\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'parent_id' => null,
            'label' => fake()->words(2, true),
            'route_name' => fake()->slug(),
            'permission' => fake()->word().'.'.fake()->word(),
            'icon' => null,
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
