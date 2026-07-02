<?php

namespace App\Jobs;

use App\Exceptions\GreenApiException;
use App\Jobs\Middleware\RateLimitedWithRedis;
use App\Models\Integration\Mensaje;
use App\Services\GreenApi\InstanceClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TransactionalMessageJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $mensajeId,
    ) {
        $this->onQueue('transactional');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $mensaje = Mensaje::query()->withoutGlobalScope('tenant')->find($this->mensajeId);
        $tenantKey = $mensaje?->tenant_id ?? 'unknown';

        return [
            new RateLimitedWithRedis("green-api:tenant:{$tenantKey}", maxAttempts: 30, decaySeconds: 60),
        ];
    }

    public function handle(): void
    {
        $mensaje = Mensaje::query()
            ->withoutGlobalScope('tenant')
            ->with('instancia')
            ->find($this->mensajeId);

        if ($mensaje === null || $mensaje->status !== 'queued') {
            return;
        }

        $instancia = $mensaje->instancia;

        if ($instancia === null || $instancia->id_instance === null || $instancia->api_token_instance === null) {
            $mensaje->update(['status' => 'failed', 'error' => 'Instance credentials missing']);

            return;
        }

        try {
            $client = new InstanceClient(
                (string) config('services.green_api.base_url'),
                (string) $instancia->id_instance,
                (string) $instancia->api_token_instance,
            );

            $idMessage = $client->sendMessage($mensaje->recipient, $mensaje->body);

            $mensaje->update([
                'status' => 'sent',
                'green_message_id' => $idMessage,
                'sent_at' => now(),
                'error' => null,
            ]);
        } catch (GreenApiException $e) {
            Log::warning('TransactionalMessageJob Green API failure', [
                'mensaje_id' => $mensaje->id,
                'error' => $e->getMessage(),
            ]);

            if ($this->attempts() >= $this->tries) {
                $mensaje->update([
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }
}
