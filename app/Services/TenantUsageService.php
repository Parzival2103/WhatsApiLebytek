<?php

namespace App\Services;

use App\Models\Core\Tenant;
use Illuminate\Support\Facades\Cache;

class TenantUsageService
{
    /**
     * Increment a usage counter for billing/quota enforcement (Green API vertical hook).
     */
    public function increment(Tenant $tenant, string $metric, int $amount = 1): int
    {
        $key = "tenant_usage:{$tenant->id}:{$metric}";

        return (int) Cache::increment($key, $amount);
    }

    public function get(Tenant $tenant, string $metric): int
    {
        return (int) Cache::get("tenant_usage:{$tenant->id}:{$metric}", 0);
    }

    public function reset(Tenant $tenant, string $metric): void
    {
        Cache::forget("tenant_usage:{$tenant->id}:{$metric}");
    }
}
