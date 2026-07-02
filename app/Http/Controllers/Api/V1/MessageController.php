<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMessageRequest;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Integration\Instancia;
use App\Models\Integration\Mensaje;
use App\Services\GreenApi\InstanceProvisioningService;
use App\Services\Messaging\MessageSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Messages
 *
 * @authenticated
 */
class MessageController extends Controller
{
    public function __construct(
        private readonly MessageSendService $messageSendService,
        private readonly InstanceProvisioningService $provisioningService,
    ) {}

    public function store(StoreMessageRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantAccess($request);
        $validated = $request->validated();

        $instancia = Instancia::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('public_id', $validated['instancePublicId'])
            ->firstOrFail();

        $idempotencyKey = (string) $request->header('Idempotency-Key');

        $result = $this->messageSendService->queueOutbound(
            $tenantId,
            $instancia,
            $validated['recipient'],
            $validated['body'],
            $idempotencyKey,
        );

        return (new MessageResource($result['mensaje']))
            ->response()
            ->setStatusCode($result['created'] ? 202 : 200);
    }

    public function show(Request $request, Mensaje $mensaje): MessageResource
    {
        $tenantId = $this->resolveTenantAccess($request);

        if ($mensaje->tenant_id !== $tenantId) {
            abort(404);
        }

        return new MessageResource($mensaje);
    }

    private function resolveTenantAccess(Request $request): int
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        if ($user->isPlatformAdmin()) {
            return $this->provisioningService->resolveActingTenantId();
        }

        if ($user->tenant_id === null) {
            abort(403, 'Tenant access required.');
        }

        return $user->tenant_id;
    }
}
