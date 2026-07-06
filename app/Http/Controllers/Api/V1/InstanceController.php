<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreInstanceRequest;
use App\Http\Resources\Api\V1\InstanceResource;
use App\Models\Integration\Instancia;
use App\Services\GreenApi\InstanceClient;
use App\Services\GreenApi\InstanceProvisioningService;
use App\Services\GreenApi\InstanceStateSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Instances
 *
 * @authenticated
 */
class InstanceController extends Controller
{
    public function __construct(
        private readonly InstanceProvisioningService $provisioningService,
        private readonly InstanceStateSyncService $stateSync,
    ) {}

    /**
     * List instances for the acting tenant.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenantId = $this->resolveTenantAccess($request);

        $instances = Instancia::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('perPage', 15));

        return InstanceResource::collection($instances);
    }

    /**
     * Create instance (async provisioning).
     */
    public function store(StoreInstanceRequest $request): JsonResponse
    {
        $this->ensurePlatformService($request);

        $tenantId = $this->provisioningService->resolveActingTenantId();
        $validated = $request->validated();

        $result = $this->provisioningService->provision($tenantId, [
            'label' => $validated['label'],
            'externalRef' => $validated['externalRef'] ?? null,
            'purpose' => $validated['purpose'] ?? 'demo',
        ]);

        return (new InstanceResource($result['instancia']))
            ->response()
            ->setStatusCode($result['created'] ? 202 : 200);
    }

    /**
     * Show instance status.
     */
    public function show(Request $request, Instancia $instancia): InstanceResource
    {
        $tenantId = $this->resolveTenantAccess($request);
        $this->ensureInstanceBelongsToTenant($instancia, $tenantId);

        if ($instancia->status !== 'authorized') {
            $instancia = $this->stateSync->refreshFromGreen($instancia);
        }

        return new InstanceResource($instancia);
    }

    /**
     * Get QR code for instance linking.
     */
    public function qr(Request $request, Instancia $instancia): JsonResponse
    {
        $tenantId = $this->resolveTenantAccess($request);
        $this->ensureInstanceBelongsToTenant($instancia, $tenantId);

        if ($instancia->status === 'authorized') {
            abort(409, 'Instance already authorized');
        }

        if (! in_array($instancia->status, ['waiting_qr', 'configuring'], true)) {
            abort(409, 'Instance not ready for QR');
        }

        if ($instancia->qr_code !== null && $instancia->qr_expires_at !== null && $instancia->qr_expires_at->isFuture()) {
            return response()->json([
                'qr' => $instancia->qr_code,
                'expiresAt' => $instancia->qr_expires_at->toIso8601String(),
            ]);
        }

        $client = new InstanceClient(
            (string) config('services.green_api.base_url'),
            (string) $instancia->id_instance,
            (string) $instancia->api_token_instance,
        );

        $qrResult = $client->qr();

        if ($qrResult['type'] === 'alreadyLogged') {
            abort(409, 'Instance already authorized');
        }

        $expiresAt = now()->addSeconds(20);
        $instancia->update([
            'qr_code' => $qrResult['qr'],
            'qr_expires_at' => $expiresAt,
        ]);

        return response()->json([
            'qr' => $qrResult['qr'],
            'expiresAt' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Delete instance (async teardown).
     */
    public function destroy(Request $request, Instancia $instancia): JsonResponse
    {
        $this->ensurePlatformService($request);

        $tenantId = $this->provisioningService->resolveActingTenantId();
        $this->ensureInstanceBelongsToTenant($instancia, $tenantId);

        $this->provisioningService->delete($instancia);

        return response()->json(['accepted' => true], 202);
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

    private function ensurePlatformService(Request $request): void
    {
        if (! $request->user()?->isPlatformAdmin()) {
            abort(403, 'Platform service access required.');
        }
    }

    private function ensureInstanceBelongsToTenant(Instancia $instancia, int $tenantId): void
    {
        if ($instancia->tenant_id !== $tenantId) {
            abort(404);
        }
    }
}
