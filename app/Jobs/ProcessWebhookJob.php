<?php

namespace App\Jobs;

use App\Exceptions\WebhookInstanceNotReadyException;
use App\Models\Integration\Instancia;
use App\Models\Integration\Webhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWebhookJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $webhookId,
    ) {
        $this->onQueue('webhooks');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(): void
    {
        $webhook = Webhook::query()->find($this->webhookId);

        if ($webhook === null || $webhook->processed_at !== null) {
            return;
        }

        $idInstance = $webhook->id_instance;

        $instancia = $idInstance !== null && $idInstance !== ''
            ? Instancia::query()
                ->withoutGlobalScope('tenant')
                ->where('id_instance', $idInstance)
                ->first()
            : null;

        if ($instancia !== null && $webhook->tenant_id === null) {
            $webhook->tenant_id = $instancia->tenant_id;
            $webhook->save();
        }

        if ($webhook->type_webhook === 'stateInstanceChanged') {
            $this->handleStateInstanceChanged($webhook, $instancia);
        }

        // All other types are persist-only in v1: the row already captured the
        // full payload, so there is nothing left to translate into domain state.

        $webhook->forceFill(['processed_at' => now()])->save();
    }

    private function handleStateInstanceChanged(Webhook $webhook, ?Instancia $instancia): void
    {
        $payload = $webhook->payload;
        $state = (string) ($payload['stateInstance'] ?? '');

        if ($state === '') {
            return;
        }

        if ($instancia === null) {
            throw new WebhookInstanceNotReadyException(
                "Instance [{$webhook->id_instance}] not found for stateInstanceChanged webhook [{$webhook->id}]."
            );
        }

        $attributes = ['green_state' => $state];

        if ($state === 'authorized') {
            $attributes['status'] = 'authorized';
            $attributes['authorized_at'] = now();
        } elseif ($state === 'notAuthorized' && $instancia->status === 'authorized') {
            $attributes['status'] = 'waiting_qr';
            $attributes['authorized_at'] = null;
        }

        $instancia->update($attributes);
    }
}
