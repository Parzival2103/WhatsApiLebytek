<?php

namespace App\Services\GreenApi;

use App\Exceptions\GreenApiException;
use App\Models\Integration\Instancia;
use Illuminate\Support\Facades\Log;

class InstanceStateSyncService
{
    public function refreshFromGreen(Instancia $instancia): Instancia
    {
        if ($instancia->id_instance === null || $instancia->api_token_instance === null) {
            return $instancia;
        }

        $client = new InstanceClient(
            (string) config('services.green_api.base_url'),
            (string) $instancia->id_instance,
            (string) $instancia->api_token_instance,
        );

        try {
            $greenState = $client->getStateInstance();
        } catch (GreenApiException $e) {
            Log::warning('Green state sync skipped; serving cached instance status', [
                'instancia_id' => $instancia->id,
                'public_id' => $instancia->public_id,
                'status' => $instancia->status,
                'error' => $e->getMessage(),
                'http_status' => $e->statusCode(),
            ]);

            return $instancia->fresh() ?? $instancia;
        }

        $attributes = ['green_state' => $greenState];

        if ($greenState === 'authorized') {
            $attributes['status'] = 'authorized';
            $attributes['authorized_at'] = $instancia->authorized_at ?? now();
            $attributes['last_error'] = null;
        } elseif ($greenState === 'notAuthorized' && $instancia->status === 'authorized') {
            $attributes['status'] = 'waiting_qr';
            $attributes['authorized_at'] = null;
        } elseif ($instancia->status === 'configuring' && $greenState === 'notAuthorized') {
            $attributes['status'] = 'waiting_qr';
            $attributes['last_error'] = null;
        } elseif ($instancia->status === 'failed' && in_array($greenState, ['notAuthorized', 'authorized'], true)) {
            // Recover false-failed rows after Green token race (setSettings 401).
            $attributes['status'] = $greenState === 'authorized' ? 'authorized' : 'waiting_qr';
            $attributes['last_error'] = null;
            $attributes['authorized_at'] = $greenState === 'authorized'
                ? ($instancia->authorized_at ?? now())
                : null;
        }

        $instancia->update($attributes);

        return $instancia->fresh() ?? $instancia;
    }
}
