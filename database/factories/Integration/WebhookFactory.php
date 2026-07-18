<?php

namespace Database\Factories\Integration;

use App\Models\Integration\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    protected $model = Webhook::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => sha1((string) Str::ulid()),
            'type_webhook' => 'incomingMessageReceived',
            'id_instance' => (string) fake()->numerify('110########'),
            'payload' => ['typeWebhook' => 'incomingMessageReceived'],
            'processed_at' => null,
            'tenant_id' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'processed_at' => now(),
        ]);
    }
}
