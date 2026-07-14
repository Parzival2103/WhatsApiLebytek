<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Integration\Instancia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Webhooks
 *
 * Incoming webhook endpoints (HMAC signature or Bearer token + idempotency).
 */
class IncomingWebhookController extends Controller
{
    /**
     * Receive incoming webhook
     *
     * Accepts signed webhook payloads (HMAC `X-Webhook-Signature` or Green `Authorization: Bearer`).
     * Green may omit `X-Event-Id`; idempotency falls back to payload-derived keys.
     * Duplicate deliveries with the same idempotency key return 200 without reprocessing.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if ((bool) $request->attributes->get('webhook_duplicate', false)) {
            return response()->json([
                'received' => true,
                'duplicate' => true,
            ]);
        }

        $payload = $request->all();
        $typeWebhook = (string) ($payload['typeWebhook'] ?? '');

        if ($typeWebhook === 'stateInstanceChanged') {
            $this->handleStateInstanceChanged($payload);
        }

        return response()->json([
            'received' => true,
            'duplicate' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleStateInstanceChanged(array $payload): void
    {
        $instanceData = $payload['instanceData'] ?? [];
        $idInstance = (string) ($instanceData['idInstance'] ?? $payload['idInstance'] ?? '');
        $state = (string) ($payload['stateInstance'] ?? '');

        if ($idInstance === '' || $state === '') {
            return;
        }

        $instancia = Instancia::query()
            ->withoutGlobalScope('tenant')
            ->where('id_instance', $idInstance)
            ->first();

        if ($instancia === null) {
            return;
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
