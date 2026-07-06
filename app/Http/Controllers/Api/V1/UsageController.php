<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Integration\Mensaje;
use App\Services\GreenApi\InstanceProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Usage
 *
 * @authenticated
 */
class UsageController extends Controller
{
    public function __construct(
        private readonly InstanceProvisioningService $provisioningService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantAccess($request);

        $query = Mensaje::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId);

        $outbound = (clone $query)->where('direction', 'outbound');
        $inbound = (clone $query)->where('direction', 'inbound');

        return response()->json([
            'messagesSent' => $outbound->count(),
            'messagesReceived' => $inbound->count(),
            'messagesSentByStatus' => [
                'sent' => (clone $outbound)->where('status', 'sent')->count(),
                'queued' => (clone $outbound)->where('status', 'queued')->count(),
                'failed' => (clone $outbound)->where('status', 'failed')->count(),
            ],
        ]);
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
