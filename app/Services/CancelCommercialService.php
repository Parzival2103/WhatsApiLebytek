<?php

namespace App\Services;

use App\Models\Core\Tenant;
use Illuminate\Support\Facades\DB;

class CancelCommercialService
{
    public function __construct(
        private readonly TenantTokenService $tenantTokenService,
    ) {}

    /**
     * @return array{tenant: Tenant, tokensRevoked: int, created: bool}
     */
    public function cancel(Tenant $tenant, ?string $reason = null): array
    {
        return DB::transaction(function () use ($tenant, $reason): array {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $meta = $tenant->meta ?? [];

            if ($tenant->commercial_status === 'cancelled') {
                return [
                    'tenant' => $tenant,
                    'tokensRevoked' => 0,
                    'created' => false,
                ];
            }

            $meta['cancelled_at'] = now()->toIso8601String();
            if ($reason !== null && $reason !== '') {
                $meta['cancel_reason'] = $reason;
            }

            $tenant->forceFill([
                'commercial_status' => 'cancelled',
                'meta' => $meta,
            ])->save();

            $revoked = $this->tenantTokenService->revokeClientTokens($tenant);

            return [
                'tenant' => $tenant->fresh(),
                'tokensRevoked' => $revoked,
                'created' => true,
            ];
        });
    }
}
