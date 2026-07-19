<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ActivatePlanRequest;
use App\Http\Requests\Api\V1\CancelCommercialRequest;
use App\Http\Requests\Api\V1\ReactivateCommercialRequest;
use App\Http\Requests\Api\V1\StoreTenantRequest;
use App\Http\Requests\Api\V1\StoreTenantTokenRequest;
use App\Http\Requests\Api\V1\UpdateTenantRequest;
use App\Http\Resources\Api\V1\TenantResource;
use App\Http\Resources\Api\V1\TenantTokenResource;
use App\Models\Core\Tenant;
use App\Services\ActivatePlanService;
use App\Services\CancelCommercialService;
use App\Services\ReactivateCommercialService;
use App\Services\TenantProvisioningService;
use App\Services\TenantTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Tenants
 *
 * @authenticated
 */
class TenantController extends Controller
{
    public function __construct(
        private readonly TenantProvisioningService $provisioningService,
        private readonly TenantTokenService $tenantTokenService,
        private readonly ActivatePlanService $activatePlanService,
        private readonly CancelCommercialService $cancelCommercialService,
        private readonly ReactivateCommercialService $reactivateCommercialService,
    ) {}

    /**
     * List tenants
     *
     * Platform service only. Returns paginated tenants.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensurePlatformService($request);

        $tenants = Tenant::query()
            ->orderBy('name')
            ->paginate((int) $request->integer('perPage', 15));

        return TenantResource::collection($tenants);
    }

    /**
     * Create tenant
     *
     * Idempotent when `externalRef` is supplied and already exists.
     */
    public function store(StoreTenantRequest $request): JsonResponse
    {
        $this->ensurePlatformService($request);

        $validated = $request->validated();
        $validated['slug'] = $this->provisioningService->normalizeSlug($validated['slug']);

        $result = $this->provisioningService->provision([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'externalRef' => $validated['externalRef'] ?? null,
        ]);

        return (new TenantResource($result['tenant']))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }

    /**
     * Show tenant
     */
    public function show(Request $request, Tenant $tenant): TenantResource
    {
        $this->ensureTenantAccess($request, $tenant);

        return new TenantResource($tenant);
    }

    /**
     * Update tenant
     */
    public function update(UpdateTenantRequest $request, Tenant $tenant): TenantResource
    {
        $this->ensurePlatformService($request);

        $tenant = $this->provisioningService->update($tenant, $request->validated());

        return new TenantResource($tenant);
    }

    /**
     * Issue tenant API token
     *
     * Platform service only. Returns plain token once.
     */
    public function issueToken(StoreTenantTokenRequest $request, Tenant $tenant): JsonResponse
    {
        $this->ensurePlatformService($request);

        $validated = $request->validated();
        $abilities = $validated['abilities'] ?? config('permissions.demo_client_abilities');

        $accessToken = $this->tenantTokenService->issue(
            $tenant,
            $validated['name'],
            $abilities,
        );

        return (new TenantTokenResource($accessToken))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Activate paid plan (platform only). Rotates tenant api-client token.
     */
    public function activatePlan(ActivatePlanRequest $request, Tenant $tenant): JsonResponse
    {
        $this->ensurePlatformService($request);

        $result = $this->activatePlanService->activate($tenant, $request->validated());

        return response()->json([
            'tenant' => (new TenantResource($result['tenant']))->resolve(),
            'token' => $result['token'],
            'plan' => $result['plan'],
        ], $result['created'] ? 201 : 200);
    }

    /**
     * Soft-cancel commercial access (platform only). Revokes client tokens; keeps tenant and instances.
     */
    public function cancelCommercial(CancelCommercialRequest $request, Tenant $tenant): JsonResponse
    {
        $this->ensurePlatformService($request);

        $reason = $request->validated('reason');
        $result = $this->cancelCommercialService->cancel(
            $tenant,
            is_string($reason) && $reason !== '' ? $reason : null,
        );

        return response()->json([
            'tenant' => (new TenantResource($result['tenant']))->resolve(),
            'commercialStatus' => 'cancelled',
            'tokensRevoked' => $result['tokensRevoked'],
        ], 200);
    }

    /**
     * Reactivate commercial access after soft-cancel (platform only). Issues a fresh client token.
     */
    public function reactivateCommercial(ReactivateCommercialRequest $request, Tenant $tenant): JsonResponse
    {
        $this->ensurePlatformService($request);

        $tokenName = $request->validated('tokenName');
        $result = $this->reactivateCommercialService->reactivate($tenant, [
            'tokenName' => is_string($tokenName) && $tokenName !== '' ? $tokenName : 'membresia-reactivated',
        ]);

        return response()->json([
            'tenant' => (new TenantResource($result['tenant']))->resolve(),
            'commercialStatus' => 'active',
            'token' => $result['token'],
        ], $result['created'] ? 201 : 200);
    }

    private function ensurePlatformService(Request $request): void
    {
        if (! $request->user()?->isPlatformAdmin()) {
            abort(403, 'Platform service access required.');
        }
    }

    private function ensureTenantAccess(Request $request, Tenant $tenant): void
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        if ($user->isPlatformAdmin()) {
            return;
        }

        if ($user->tenant_id !== $tenant->id) {
            abort(403, 'Tenant access denied.');
        }
    }
}
