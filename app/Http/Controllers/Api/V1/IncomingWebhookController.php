<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Webhooks
 *
 * Incoming webhook endpoints (HMAC signature + idempotency).
 */
class IncomingWebhookController extends Controller
{
    /**
     * Receive incoming webhook
     *
     * Accepts signed webhook payloads. Duplicate deliveries with the same idempotency key return 200 without reprocessing.
     *
     * @header X-Webhook-Signature required HMAC-SHA256 signature of the raw body.
     * @header X-Idempotency-Key required Unique key for this delivery.
     *
     * @bodyParam payload object optional Example webhook body.
     *
     * @response 200 {"received": true, "duplicate": false}
     * @response 401 {"message": "Invalid signature"}
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'received' => true,
            'duplicate' => (bool) $request->attributes->get('webhook_duplicate', false),
        ]);
    }
}
