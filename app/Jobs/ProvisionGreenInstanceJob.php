<?php

namespace App\Jobs;

use App\Exceptions\GreenApiException;
use App\Jobs\Middleware\RateLimitedWithRedis;
use App\Models\Integration\Instancia;
use App\Services\GreenApi\GreenApiInstanceSettings;
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

    private const SET_SETTINGS_INITIAL_DELAY_SECONDS = 3;

    private const SET_SETTINGS_RETRY_DELAY_SECONDS = 3;

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

        try {
            $idInstance = $instancia->id_instance !== null ? (string) $instancia->id_instance : '';
            $apiTokenInstance = filled($instancia->api_token_instance)
                ? (string) $instancia->api_token_instance
                : '';

            $freshGreenCredentials = false;
            $client = null;

            if ($idInstance === '' || $apiTokenInstance === '') {
                $credentials = $partnerClient->createInstance($instancia->label);

                $instancia->update([
                    'id_instance' => $credentials['idInstance'],
                    'api_token_instance' => $credentials['apiTokenInstance'],
                    'status' => 'configuring',
                    'last_error' => null,
                ]);

                $idInstance = $credentials['idInstance'];
                $apiTokenInstance = $credentials['apiTokenInstance'];
                $freshGreenCredentials = true;
            } elseif ($instancia->status !== 'configuring') {
                $instancia->update([
                    'status' => 'configuring',
                    'last_error' => null,
                ]);
            }

            $client = new InstanceClient(
                (string) config('services.green_api.base_url'),
                $idInstance,
                $apiTokenInstance,
            );

            $this->applyGreenSettings($client, $freshGreenCredentials);

            $greenState = $client->getStateInstance();
            $status = $greenState === 'authorized' ? 'authorized' : 'waiting_qr';

            $instancia->update([
                'green_state' => $greenState,
                'status' => $status,
                'last_error' => null,
                'authorized_at' => $status === 'authorized' ? now() : null,
            ]);
        } catch (GreenApiException $e) {
            if ($this->attempts() >= $this->tries) {
                if ($client instanceof InstanceClient
                    && $this->finalizeDespiteSettingsFailure($client, $instancia, $e)) {
                    return;
                }

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

    private function finalizeDespiteSettingsFailure(
        InstanceClient $client,
        Instancia $instancia,
        GreenApiException $settingsError,
    ): bool {
        if (! str_contains($settingsError->getMessage(), 'setSettings')) {
            return false;
        }

        if ($instancia->id_instance === null || ! filled($instancia->api_token_instance)) {
            return false;
        }

        try {
            $greenState = $client->getStateInstance();
        } catch (GreenApiException) {
            return false;
        }

        if (! in_array($greenState, ['notAuthorized', 'authorized'], true)) {
            return false;
        }

        $status = $greenState === 'authorized' ? 'authorized' : 'waiting_qr';

        $instancia->update([
            'green_state' => $greenState,
            'status' => $status,
            'last_error' => null,
            'authorized_at' => $status === 'authorized'
                ? ($instancia->authorized_at ?? now())
                : null,
        ]);

        Log::warning('ProvisionGreenInstanceJob marked instance usable despite setSettings failure', [
            'instancia_id' => $instancia->id,
            'green_state' => $greenState,
            'error' => $settingsError->getMessage(),
        ]);

        return true;
    }

    private function applyGreenSettings(InstanceClient $client, bool $freshGreenCredentials): void
    {
        $settings = [
            'webhookUrl' => config('services.green_api.webhook_url'),
            'webhookUrlToken' => config('services.green_api.webhook_secret'),
            'incomingWebhook' => 'yes',
            'stateWebhook' => 'yes',
            'delaySendMessagesMilliseconds' => GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS,
        ];

        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($freshGreenCredentials) {
                sleep(self::SET_SETTINGS_INITIAL_DELAY_SECONDS + (($attempt - 1) * self::SET_SETTINGS_RETRY_DELAY_SECONDS));
            }

            try {
                $client->setSettings($settings);

                return;
            } catch (GreenApiException $e) {
                $retryable = $freshGreenCredentials
                    && $e->statusCode() === 401
                    && $attempt < $maxAttempts;

                if (! $retryable) {
                    throw $e;
                }

                Log::warning('ProvisionGreenInstanceJob setSettings retry after Green 401', [
                    'instancia_id' => $this->instanciaId,
                    'attempt' => $attempt,
                ]);
            }
        }
    }
}
