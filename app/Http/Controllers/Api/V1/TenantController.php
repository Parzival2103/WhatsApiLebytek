<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTenantRequest;
use App\Http\Requests\Api\V1\UpdateTenantRequest;
use App\Http\Resources\Api\V1\TenantResource;
use App\Models\Core\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Tenants
 * @authenticated
 */
class TenantController extends Controller
{
    public function __construct(
        private readonly TenantProvisioningService $provisioningService,
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
