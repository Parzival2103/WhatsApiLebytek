<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\HealthResource;
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

        return (new HealthResource([
            'status' => $status,
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ]))->response();
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
