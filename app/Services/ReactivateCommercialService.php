<?php

namespace App\Services;

use App\Models\Core\Tenant;
use Illuminate\Support\Facades\DB;

class ReactivateCommercialService
{
    public function __construct(
        private readonly TenantTokenService $tenantTokenService,
    ) {}

    /**
     * @param  array{tokenName?: string|null}  $options
     * @return array{tenant: Tenant, token: string|null, created: bool}
     */
    public function reactivate(Tenant $tenant, array $options = []): array
    {
        $tokenName = $options['tokenName'] ?? 'membresia-reactivated';
        $abilities = config('permissions.demo_client_abilities');

        return DB::transaction(function () use ($tenant, $tokenName, $abilities): array {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $meta = $tenant->meta ?? [];

            if ($tenant->commercial_status === 'active') {
                return [
                    'tenant' => $tenant,
                    'token' => null,
                    'created' => false,
                ];
            }

            unset($meta['cancelled_at'], $meta['cancel_reason']);
            $meta['reactivated_at'] = now()->toIso8601String();

            $tenant->forceFill([
                'commercial_status' => 'active',
                'meta' => $meta,
            ])->save();

            $this->tenantTokenService->revokeClientTokens($tenant);
            $accessToken = $this->tenantTokenService->issue($tenant, $tokenName, $abilities);

            return [
                'tenant' => $tenant->fresh(),
                'token' => $accessToken->plainTextToken,
                'created' => true,
            ];
        });
    }
}
