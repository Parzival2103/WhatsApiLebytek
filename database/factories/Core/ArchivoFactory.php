<?php

namespace Database\Factories\Core;

use App\Models\Core\Archivo;
use App\Models\Core\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Archivo>
 */
class ArchivoFactory extends Factory
{
    protected $model = Archivo::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'tenant_id' => Tenant::factory(),
            'disk' => 'local',
            'path' => 'uploads/'.Str::random(40).'.png',
            'original_name' => fake()->word().'.png',
            'mime_type' => 'image/png',
            'size' => fake()->numberBetween(1024, 1048576),
            'hash' => hash('sha256', Str::random()),
            'purpose' => fake()->randomElement(['logo', 'favicon', 'pwa_icon']),
        ];
    }
}
