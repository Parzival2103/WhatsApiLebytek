<?php

namespace Database\Factories\Integration;

use App\Models\Core\Tenant;
use App\Models\Integration\Credencial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Credencial>
 */
class CredencialFactory extends Factory
{
    protected $model = Credencial::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'provider' => fake()->unique()->word(),
            'credentials' => ['api_key' => fake()->uuid()],
        ];
    }
}
