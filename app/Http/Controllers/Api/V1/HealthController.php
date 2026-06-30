<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\HealthResource;
use App\Models\Core\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * @group Platform
 * @authenticated
 */
class HealthController extends Controller
{
    /**
     * Health check
     *
     * Returns database and Redis connectivity for monitoring.
     */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ];

        $status = collect($checks)->every(fn (array $check): bool => $check['ok'])
            ? 'ok'
            : 'degraded';

        $payload = [
            'status' => $status,
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
            'actingTenant' => $this->resolveActingTenantPublicId(),
        ];

        return (new HealthResource($payload))->response();
    }

    private function resolveActingTenantPublicId(): ?string
    {
        $tenantId = TenantContext::id();

        if ($tenantId === null) {
            return null;
        }

        return Tenant::query()->whereKey($tenantId)->value('public_id');
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['ok' => true, 'message' => 'connected'];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => 'unavailable'];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function checkRedis(): array
    {
        try {
            Redis::connection()->ping();

            return ['ok' => true, 'message' => 'connected'];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => 'unavailable'];
        }
    }
}
