<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookJob;
use App\Models\Integration\Webhook;
use App\Support\WebhookEventId;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Webhooks
 *
 * Incoming webhook endpoint (HMAC signature or Bearer token).
 *
 * Every valid event is persisted to int_webhooks before processing. The
 * event_id unique index is the deduplication authority; a collision returns
 * 200 {duplicate:true}. Processing happens asynchronously in ProcessWebhookJob.
 */
class IncomingWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();
        $eventId = sha1(WebhookEventId::resolve($request));

        $instanceData = is_array($payload['instanceData'] ?? null) ? $payload['instanceData'] : [];
        $idInstance = $instanceData['idInstance'] ?? $payload['idInstance'] ?? null;

        try {
            $webhook = Webhook::query()->create([
                'event_id' => $eventId,
                'type_webhook' => (string) ($payload['typeWebhook'] ?? ''),
                'id_instance' => $idInstance !== null ? (string) $idInstance : null,
                'payload' => $payload,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return response()->json(['received' => true, 'duplicate' => true]);
            }

            throw $e;
        }

        ProcessWebhookJob::dispatch($webhook->id);

        return response()->json(['received' => true, 'duplicate' => false]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }
}
