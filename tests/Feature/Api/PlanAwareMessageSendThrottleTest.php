<?php

use App\Models\Core\Tenant;
use App\Models\User;
use App\Support\PlanRateResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('messages-send limiter allow matches tenant plan catalog', function () {
    $tenant = Tenant::factory()->create([
        'slug' => 'rate-starter',
        'plan_slug' => 'starter',
    ]);
    $user = User::factory()->forTenant($tenant)->create();

    $request = Request::create('/api/v1/messages', 'POST');
    $request->setUserResolver(fn () => $user);

    $limiter = RateLimiter::limiter('messages-send');
    /** @var Limit|array<int, Limit> $limits */
    $limits = $limiter($request);
    $limit = is_array($limits) ? $limits[0] : $limits;

    expect($limit->maxAttempts)->toBe(PlanRateResolver::httpSendPerMinute($tenant));
});
