<?php

namespace App\Services\GreenApi;

use App\Models\Integration\Instancia;

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

        $greenState = $client->getStateInstance();
        $attributes = ['green_state' => $greenState];

        if ($greenState === 'authorized') {
            $attributes['status'] = 'authorized';
            $attributes['authorized_at'] = $instancia->authorized_at ?? now();
        } elseif ($greenState === 'notAuthorized' && $instancia->status === 'authorized') {
            $attributes['status'] = 'waiting_qr';
            $attributes['authorized_at'] = null;
        } elseif ($instancia->status === 'configuring' && $greenState === 'notAuthorized') {
            $attributes['status'] = 'waiting_qr';
        }

        $instancia->update($attributes);

        return $instancia->fresh() ?? $instancia;
    }
}
