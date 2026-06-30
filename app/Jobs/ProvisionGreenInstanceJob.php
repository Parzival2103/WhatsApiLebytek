<?php

namespace App\Jobs;

use App\Exceptions\GreenApiException;
use App\Jobs\Middleware\RateLimitedWithRedis;
use App\Models\Integration\Instancia;
use App\Services\GreenApi\InstanceClient;
use App\Services\GreenApi\PartnerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProvisionGreenInstanceJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $instanciaId,
    ) {
        $this->onQueue('provisioning');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new RateLimitedWithRedis('green-api:partner', maxAttempts: 10, decaySeconds: 60),
        ];
    }

    public function handle(PartnerClient $partnerClient): void
    {
        $instancia = Instancia::query()->withoutGlobalScope('tenant')->find($this->instanciaId);

        if ($instancia === null || in_array($instancia->status, ['authorized', 'failed', 'deleted'], true)) {
            return;
        }

        if ($instancia->id_instance !== null) {
            return;
        }

        try {
            $credentials = $partnerClient->createInstance($instancia->label);

            $instancia->update([
                'id_instance' => $credentials['idInstance'],
                'api_token_instance' => $credentials['apiTokenInstance'],
                'status' => 'configuring',
            ]);

            $client = new InstanceClient(
                (string) config('services.green_api.base_url'),
                $credentials['idInstance'],
                $credentials['apiTokenInstance'],
            );

            $client->setSettings([
                'webhookUrl' => config('services.green_api.webhook_url'),
                'webhookUrlToken' => config('services.green_api.webhook_secret'),
                'incomingWebhook' => 'yes',
                'stateWebhook' => 'yes',
            ]);

            $greenState = $client->getStateInstance();
            $status = $greenState === 'authorized' ? 'authorized' : 'waiting_qr';

            $instancia->update([
                'green_state' => $greenState,
                'status' => $status,
                'authorized_at' => $status === 'authorized' ? now() : null,
            ]);
        } catch (GreenApiException $e) {
            if ($this->attempts() >= $this->tries) {
                $instancia->update([
                    'status' => 'failed',
                    'last_error' => $e->getMessage(),
                ]);

                Log::error('ProvisionGreenInstanceJob failed', [
                    'instancia_id' => $instancia->id,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            throw $e;
        }
    }
}
