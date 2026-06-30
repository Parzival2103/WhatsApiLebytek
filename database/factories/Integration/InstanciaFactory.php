<?php

namespace Database\Factories\Integration;

use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Instancia>
 */
class InstanciaFactory extends Factory
{
    protected $model = Instancia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'tenant_id' => Tenant::factory(),
            'label' => fake()->words(2, true),
            'purpose' => 'demo',
            'status' => 'provisioning',
            'provider' => 'green_api',
        ];
    }

    public function authorized(): static
    {
        return $this->state(fn (array $attributes) => [
            'id_instance' => (string) fake()->numerify('110########'),
            'api_token_instance' => fake()->uuid(),
            'status' => 'authorized',
            'green_state' => 'authorized',
            'authorized_at' => now(),
        ]);
    }
}
