<?php

namespace Database\Factories\Integration;

use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use App\Models\Integration\Mensaje;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Mensaje> */
class MensajeFactory extends Factory
{
    protected $model = Mensaje::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'instancia_id' => Instancia::factory(),
            'direction' => 'outbound',
            'recipient' => '5215512345678',
            'body' => 'Test message',
            'status' => 'queued',
        ];
    }
}
