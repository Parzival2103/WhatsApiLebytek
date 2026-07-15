<?php

namespace App\Support;

use App\Models\Core\Tenant;

final class PlanRateResolver
{
    public static function httpSendPerMinute(?Tenant $tenant): int
    {
        $slug = $tenant?->plan_slug ?: config('plans.default_slug', 'demo');
        $plan = PlanCatalog::definition($slug) ?? PlanCatalog::definition('demo');

        return (int) ($plan['http_send_per_minute'] ?? 10);
    }

    public static function jobSendPerMinute(?Tenant $tenant): int
    {
        $slug = $tenant?->plan_slug ?: config('plans.default_slug', 'demo');
        $plan = PlanCatalog::definition($slug) ?? PlanCatalog::definition('demo');

        return (int) ($plan['job_send_per_minute'] ?? 30);
    }
}
