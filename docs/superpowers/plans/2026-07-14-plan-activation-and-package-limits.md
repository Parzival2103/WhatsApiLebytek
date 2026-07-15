# Plan Activation + Package Limits — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a platform-only `POST /api/v1/tenants/{tenant}/activate-plan` that atomically upgrades a demo tenant to a paid catalog plan (quota + commercial fields), revokes demo Sanctum tokens, issues a fresh client token, and makes HTTP/job send throttles plan-aware.

**Architecture:** Introduce `config/plans.php` as the only source of truth for slug → monthly quota + HTTP/job rates. `ActivatePlanService` runs inside a DB transaction: resolve catalog limits, update tenant (including new JSON `meta`), revoke api-client tokens via `TenantTokenService`, issue a new token with `demo_client_abilities`. Wire `RateLimiter::for('messages-send')` and `TransactionalMessageJob` Redis throttle to the same catalog through a tiny `PlanRateResolver`. Contract docs sync for Framework.

**Tech Stack:** Laravel 13, Sanctum, Pest, Redis throttle middleware, existing platform RBAC (`tenants.gestionar` + `ensurePlatformService`)

**Spec:** [`docs/superpowers/specs/2026-07-14-plan-activation-and-package-limits-design.md`](../specs/2026-07-14-plan-activation-and-package-limits-design.md)

**Companion (out of this repo’s PR):** Framework Authorize → `LebytekApiClient::activatePlan` after this endpoint ships.

## Global constraints

- **Platform only.** Tenant Bearer must get 403 on activate-plan; never accept arbitrary quotas for non-`empresa` slugs.
- **No re-provision / no new Green instance.** Same tenant + instance; Hybrid option A.
- **Plain token once.** Same pattern as `issueToken` / `TenantTokenResource`.
- **Idempotency-Key required** (v1 middleware). Semantic replay of same `planSlug`+`orderExternalRef`: 200 with `token: null`, no second revoke/issue.
- **Do not weaken** `AccountStatusService` / monthly quota enforcement — only raise the limit from catalog.
- **No deploy / SSH / VPS** unless the user explicitly orders it.
- **TDD:** failing tests before implementation in each code task.
- **Commits:** only when the user asks (or when executing this plan with explicit commit approval). Messages below are suggested.

---

## File map

| File | Action | Responsibility |
|------|--------|----------------|
| `config/plans.php` | Create | Canonical slug → name, monthly limit, HTTP/min, job Redis/min, empresa min/max |
| `database/migrations/2026_07_14_160000_add_meta_to_core_tenants_table.php` | Create | JSON `meta` on `core_tenants` |
| `app/Models/Core/Tenant.php` | Modify | fillable + cast `meta` array |
| `app/Support/PlanCatalog.php` | Create | Resolve plan definition + monthly limit (empresa override) |
| `app/Support/PlanRateResolver.php` | Create | HTTP / job rates for tenant plan_slug (fallback `demo`) |
| `app/Services/TenantTokenService.php` | Modify | `revokeClientTokens(Tenant)` + keep `issue` |
| `app/Services/ActivatePlanService.php` | Create | Atomic activate + token rotate + semantic idempotency |
| `app/Http/Requests/Api/V1/ActivatePlanRequest.php` | Create | Validate body |
| `app/Http/Controllers/Api/V1/TenantController.php` | Modify | `activatePlan` action |
| `routes/api.php` | Modify | `POST …/activate-plan` |
| `app/Providers/AppServiceProvider.php` | Modify | Plan-aware `messages-send` limiter |
| `app/Jobs/TransactionalMessageJob.php` | Modify | Plan-aware Redis `maxAttempts` |
| `app/Http/Resources/Api/V1/TenantResource.php` | Modify | Optional: expose nothing new (meta stays internal) — no change if meta not public |
| `tests/Unit/Support/PlanCatalogTest.php` | Create | Catalog + empresa override rules |
| `tests/Unit/Support/PlanRateResolverTest.php` | Create | Rate lookup + demo fallback |
| `tests/Unit/Services/TenantTokenServiceTest.php` | Modify | Revoke + reissue |
| `tests/Feature/Api/ActivatePlanTest.php` | Create | Platform activate, tenant 403, token rotate, empresa, idempotency |
| `tests/Feature/Api/PlanAwareMessageSendThrottleTest.php` | Create | HTTP limiter uses plan config |
| `docs/integration/waapi-api-contract.md` | Modify | Document activate-plan + discourage bare PATCH for paid unlock |

---

### Task 1: Plan catalog config + unit tests

**Files:**
- Create: `config/plans.php`
- Create: `tests/Unit/Support/PlanCatalogTest.php`
- Create: `app/Support/PlanCatalog.php`

- [ ] **Step 1: Write failing PlanCatalog tests**

Create `tests/Unit/Support/PlanCatalogTest.php`:

```php
<?php

use App\Support\PlanCatalog;

test('catalog resolves starter monthly limit and rates', function () {
    $plan = PlanCatalog::definition('starter');

    expect($plan)->not->toBeNull()
        ->and($plan['name'])->toBe('Starter')
        ->and($plan['messages_monthly_limit'])->toBe(5000)
        ->and($plan['http_send_per_minute'])->toBe(30)
        ->and($plan['job_send_per_minute'])->toBe(60);
});

test('unknown slug returns null definition', function () {
    expect(PlanCatalog::definition('nope'))->toBeNull();
});

test('resolveMessagesMonthlyLimit ignores override for starter', function () {
    expect(PlanCatalog::resolveMessagesMonthlyLimit('starter', 999999))->toBe(5000);
});

test('resolveMessagesMonthlyLimit accepts empresa override in range', function () {
    expect(PlanCatalog::resolveMessagesMonthlyLimit('empresa', 250000))->toBe(250000);
});

test('resolveMessagesMonthlyLimit rejects empresa override below min', function () {
    expect(fn () => PlanCatalog::resolveMessagesMonthlyLimit('empresa', 1))
        ->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run tests — expect FAIL**

Run:

```bash
php artisan test tests/Unit/Support/PlanCatalogTest.php
```

Expected: FAIL — `App\Support\PlanCatalog` not found.

- [ ] **Step 3: Add config + PlanCatalog**

Create `config/plans.php`:

```php
<?php

return [
    'default_slug' => 'demo',

    'empresa' => [
        'messages_monthly_limit_min' => 1000,
        'messages_monthly_limit_max' => 10_000_000,
    ],

    'catalog' => [
        'demo' => [
            'name' => 'Demo',
            'messages_monthly_limit' => 100,
            'http_send_per_minute' => 10,
            'job_send_per_minute' => 30,
        ],
        'starter' => [
            'name' => 'Starter',
            'messages_monthly_limit' => 5000,
            'http_send_per_minute' => 30,
            'job_send_per_minute' => 60,
        ],
        'business' => [
            'name' => 'Business',
            'messages_monthly_limit' => 80000,
            'http_send_per_minute' => 60,
            'job_send_per_minute' => 120,
        ],
        'empresa' => [
            'name' => 'Enterprise',
            // null = must supply messagesMonthlyLimit on activate-plan
            'messages_monthly_limit' => null,
            'http_send_per_minute' => 120,
            'job_send_per_minute' => 180,
        ],
    ],
];
```

Create `app/Support/PlanCatalog.php`:

```php
<?php

namespace App\Support;

use InvalidArgumentException;

final class PlanCatalog
{
    /**
     * @return array{name: string, messages_monthly_limit: int|null, http_send_per_minute: int, job_send_per_minute: int}|null
     */
    public static function definition(string $slug): ?array
    {
        $plan = config("plans.catalog.{$slug}");

        return is_array($plan) ? $plan : null;
    }

    public static function resolveMessagesMonthlyLimit(string $slug, ?int $override): ?int
    {
        $plan = self::definition($slug);

        if ($plan === null) {
            throw new InvalidArgumentException("Unknown plan slug [{$slug}].");
        }

        if ($slug === 'empresa') {
            if ($override === null) {
                throw new InvalidArgumentException('messagesMonthlyLimit is required for empresa.');
            }

            $min = (int) config('plans.empresa.messages_monthly_limit_min', 1000);
            $max = (int) config('plans.empresa.messages_monthly_limit_max', 10_000_000);

            if ($override < $min || $override > $max) {
                throw new InvalidArgumentException(
                    "messagesMonthlyLimit must be between {$min} and {$max} for empresa."
                );
            }

            return $override;
        }

        return $plan['messages_monthly_limit'];
    }
}
```

- [ ] **Step 4: Run tests — expect PASS**

```bash
php artisan test tests/Unit/Support/PlanCatalogTest.php
```

Expected: PASS (5 tests).

- [ ] **Step 5: Commit (suggested)**

```bash
git add config/plans.php app/Support/PlanCatalog.php tests/Unit/Support/PlanCatalogTest.php
git commit -m "feat(plans): add canonical plan catalog and PlanCatalog resolver"
```

---

### Task 2: Tenant `meta` JSON column

**Files:**
- Create: `database/migrations/2026_07_14_160000_add_meta_to_core_tenants_table.php`
- Modify: `app/Models/Core/Tenant.php`

- [ ] **Step 1: Write migration + model update**

Create migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_tenants', function (Blueprint $table): void {
            $table->json('meta')->nullable()->after('first_message_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('core_tenants', function (Blueprint $table): void {
            $table->dropColumn('meta');
        });
    }
};
```

Update `app/Models/Core/Tenant.php` fillable to include `'meta'`, and casts:

```php
'meta' => 'array',
```

Full fillable after change:

```php
#[Fillable([
    'name', 'slug', 'external_ref', 'is_active',
    'commercial_status', 'plan_slug', 'plan_name',
    'demo_started_at', 'demo_expires_at', 'messages_monthly_limit',
    'last_api_activity_at', 'first_message_sent_at', 'meta',
])]
```

- [ ] **Step 2: Migrate in test env and smoke**

```bash
php artisan migrate --no-interaction
php artisan test --filter=TenantProvisioning
```

Expected: migrate OK; existing tenant tests still PASS.

- [ ] **Step 3: Commit (suggested)**

```bash
git add database/migrations/2026_07_14_160000_add_meta_to_core_tenants_table.php app/Models/Core/Tenant.php
git commit -m "feat(tenants): add JSON meta column for billing activation audit"
```

---

### Task 3: Revoke client tokens on TenantTokenService

**Files:**
- Modify: `app/Services/TenantTokenService.php`
- Modify: `tests/Unit/Services/TenantTokenServiceTest.php`

- [ ] **Step 1: Write failing revoke test**

Append to `tests/Unit/Services/TenantTokenServiceTest.php` (keep existing tests):

```php
test('revokeClientTokens deletes sanctum tokens for api client user', function () {
    $tenant = Tenant::factory()->create(['slug' => 'revoke-me']);
    $service = app(TenantTokenService::class);

    $first = $service->issue($tenant, 'demo-token');
    $plain = $first->plainTextToken;

    expect($service->revokeClientTokens($tenant))->toBeGreaterThan(0);

    $this->app['auth']->forgetGuards();

    $this->withToken($plain)
        ->getJson(route('api.v1.health'))
        ->assertUnauthorized();
});
```

If `api.v1.health` requires a permission the revoked user no longer has auth for, any authenticated route that returns 401 when token is invalid is fine — prefer:

```php
    expect(
        \Laravel\Sanctum\PersonalAccessToken::findToken($plain)
    )->toBeNull();
```

Use that assertion instead of HTTP if simpler — **preferred minimal version:**

```php
test('revokeClientTokens deletes sanctum tokens for api client user', function () {
    $tenant = Tenant::factory()->create(['slug' => 'revoke-me']);
    $service = app(TenantTokenService::class);

    $issued = $service->issue($tenant, 'demo-token');
    $tokenId = $issued->accessToken->getKey();

    expect($service->revokeClientTokens($tenant))->toBe(1);
    expect(\Laravel\Sanctum\PersonalAccessToken::query()->find($tokenId))->toBeNull();
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
php artisan test --filter=revokeClientTokens
```

Expected: FAIL — method undefined.

- [ ] **Step 3: Implement revoke**

Add to `app/Services/TenantTokenService.php`:

```php
    /**
     * Revoke all Sanctum tokens for the tenant api-client user (if present).
     *
     * @return int Number of tokens deleted
     */
    public function revokeClientTokens(Tenant $tenant): int
    {
        $email = "api-client+{$tenant->slug}@tenants.lebytek.internal";
        $user = User::query()->where('email', $email)->where('tenant_id', $tenant->id)->first();

        if ($user === null) {
            return 0;
        }

        return $user->tokens()->delete();
    }
```

Do **not** revoke tokens for other tenant humans / platform users — only the synthetic api-client email.

- [ ] **Step 4: Run test — expect PASS**

```bash
php artisan test --filter=revokeClientTokens
```

Expected: PASS.

- [ ] **Step 5: Commit (suggested)**

```bash
git add app/Services/TenantTokenService.php tests/Unit/Services/TenantTokenServiceTest.php
git commit -m "feat(tokens): revoke all Sanctum tokens for tenant api-client"
```

---

### Task 4: ActivatePlanService (domain orchestration)

**Files:**
- Create: `app/Services/ActivatePlanService.php`
- Create: `tests/Unit/Services/ActivatePlanServiceTest.php`

- [ ] **Step 1: Write failing unit tests**

Create `tests/Unit/Services/ActivatePlanServiceTest.php`:

```php
<?php

use App\Models\Core\Tenant;
use App\Services\ActivatePlanService;
use App\Services\TenantTokenService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('activate upgrades demo tenant to starter and issues token', function () {
    $tenant = Tenant::factory()->create([
        'slug' => 'activate-starter',
        'commercial_status' => 'demo',
        'plan_slug' => 'demo',
        'plan_name' => 'Demo',
        'messages_monthly_limit' => 100,
        'demo_expires_at' => now()->addDays(20),
    ]);

    $demoToken = app(TenantTokenService::class)->issue($tenant, 'cliente-demo');
    $demoPlain = $demoToken->plainTextToken;

    $result = app(ActivatePlanService::class)->activate($tenant, [
        'planSlug' => 'starter',
        'billingCycle' => 'monthly',
        'orderExternalRef' => '01JXORDERSTARTER1',
        'tokenName' => 'cliente-paid-starter',
    ]);

    expect($result['created'])->toBeTrue()
        ->and($result['token'])->toBeString()->not->toBeEmpty()
        ->and($result['plan']['slug'])->toBe('starter')
        ->and($result['plan']['messagesMonthlyLimit'])->toBe(5000);

    $tenant->refresh();
    expect($tenant->commercial_status)->toBe('active')
        ->and($tenant->plan_slug)->toBe('starter')
        ->and($tenant->plan_name)->toBe('Starter')
        ->and($tenant->messages_monthly_limit)->toBe(5000)
        ->and($tenant->demo_expires_at)->toBeNull()
        ->and($tenant->meta['billing_cycle'])->toBe('monthly')
        ->and($tenant->meta['activated_order_ref'])->toBe('01JXORDERSTARTER1')
        ->and($tenant->meta['activated_at'])->not->toBeEmpty();

    expect(\Laravel\Sanctum\PersonalAccessToken::findToken($demoPlain))->toBeNull();
});

test('activate is semantically idempotent for same slug and order ref', function () {
    $tenant = Tenant::factory()->create([
        'slug' => 'activate-idem',
        'commercial_status' => 'demo',
        'messages_monthly_limit' => 100,
    ]);

    $service = app(ActivatePlanService::class);
    $first = $service->activate($tenant, [
        'planSlug' => 'starter',
        'billingCycle' => 'monthly',
        'orderExternalRef' => '01JXORDERIDEM1',
    ]);

    $second = $service->activate($tenant->fresh(), [
        'planSlug' => 'starter',
        'billingCycle' => 'monthly',
        'orderExternalRef' => '01JXORDERIDEM1',
    ]);

    expect($first['created'])->toBeTrue()
        ->and($second['created'])->toBeFalse()
        ->and($second['token'])->toBeNull();
});

test('empresa requires messagesMonthlyLimit', function () {
    $tenant = Tenant::factory()->create(['slug' => 'activate-empresa']);

    expect(fn () => app(ActivatePlanService::class)->activate($tenant, [
        'planSlug' => 'empresa',
        'billingCycle' => 'annual',
        'orderExternalRef' => '01JXORDEREMP1',
    ]))->toThrow(ValidationException::class);
});

test('empresa accepts custom limit', function () {
    $tenant = Tenant::factory()->create(['slug' => 'activate-empresa-ok']);

    $result = app(ActivatePlanService::class)->activate($tenant, [
        'planSlug' => 'empresa',
        'billingCycle' => 'annual',
        'orderExternalRef' => '01JXORDEREMP2',
        'messagesMonthlyLimit' => 250000,
    ]);

    expect($result['created'])->toBeTrue()
        ->and($tenant->fresh()->messages_monthly_limit)->toBe(250000);
});
```

- [ ] **Step 2: Run — expect FAIL**

```bash
php artisan test tests/Unit/Services/ActivatePlanServiceTest.php
```

Expected: FAIL — class missing.

- [ ] **Step 3: Implement ActivatePlanService**

Create `app/Services/ActivatePlanService.php`:

```php
<?php

namespace App\Services;

use App\Models\Core\Tenant;
use App\Support\PlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class ActivatePlanService
{
    public function __construct(
        private readonly TenantTokenService $tenantTokenService,
    ) {}

    /**
     * @param  array{
     *   planSlug: string,
     *   billingCycle: string,
     *   orderExternalRef: string,
     *   messagesMonthlyLimit?: int|null,
     *   tokenName?: string|null
     * }  $data
     * @return array{
     *   tenant: Tenant,
     *   token: string|null,
     *   plan: array{slug: string, name: string, messagesMonthlyLimit: int|null, billingCycle: string},
     *   created: bool
     * }
     */
    public function activate(Tenant $tenant, array $data): array
    {
        $slug = $data['planSlug'];
        $definition = PlanCatalog::definition($slug);

        if ($definition === null) {
            throw ValidationException::withMessages([
                'planSlug' => ["Unknown planSlug [{$slug}]."],
            ]);
        }

        $orderRef = $data['orderExternalRef'];
        $meta = $tenant->meta ?? [];

        if (
            $tenant->commercial_status === 'active'
            && $tenant->plan_slug === $slug
            && ($meta['activated_order_ref'] ?? null) === $orderRef
        ) {
            return [
                'tenant' => $tenant,
                'token' => null,
                'plan' => [
                    'slug' => $slug,
                    'name' => $definition['name'],
                    'messagesMonthlyLimit' => $tenant->messages_monthly_limit,
                    'billingCycle' => (string) ($meta['billing_cycle'] ?? $data['billingCycle']),
                ],
                'created' => false,
            ];
        }

        try {
            $limit = PlanCatalog::resolveMessagesMonthlyLimit(
                $slug,
                isset($data['messagesMonthlyLimit']) ? (int) $data['messagesMonthlyLimit'] : null,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'messagesMonthlyLimit' => [$e->getMessage()],
            ]);
        }

        $tokenName = $data['tokenName'] ?? "cliente-{$slug}";
        $abilities = config('permissions.demo_client_abilities');

        return DB::transaction(function () use ($tenant, $slug, $definition, $limit, $data, $orderRef, $tokenName, $abilities): array {
            $tenant->forceFill([
                'commercial_status' => 'active',
                'plan_slug' => $slug,
                'plan_name' => $definition['name'],
                'messages_monthly_limit' => $limit,
                'demo_expires_at' => null,
                'meta' => array_merge($tenant->meta ?? [], [
                    'billing_cycle' => $data['billingCycle'],
                    'activated_order_ref' => $orderRef,
                    'activated_at' => now()->toIso8601String(),
                ]),
            ])->save();

            $this->tenantTokenService->revokeClientTokens($tenant);
            $accessToken = $this->tenantTokenService->issue($tenant, $tokenName, $abilities);

            return [
                'tenant' => $tenant->fresh(),
                'token' => $accessToken->plainTextToken,
                'plan' => [
                    'slug' => $slug,
                    'name' => $definition['name'],
                    'messagesMonthlyLimit' => $limit,
                    'billingCycle' => $data['billingCycle'],
                ],
                'created' => true,
            ];
        });
    }
}
```

Remove unused `Throwable` import if the linter complains — do not catch broadly.

- [ ] **Step 4: Run — expect PASS**

```bash
php artisan test tests/Unit/Services/ActivatePlanServiceTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit (suggested)**

```bash
git add app/Services/ActivatePlanService.php tests/Unit/Services/ActivatePlanServiceTest.php
git commit -m "feat(plans): add ActivatePlanService with catalog limits and token rotate"
```

---

### Task 5: HTTP endpoint + FormRequest + Feature tests

**Files:**
- Create: `app/Http/Requests/Api/V1/ActivatePlanRequest.php`
- Modify: `app/Http/Controllers/Api/V1/TenantController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/ActivatePlanTest.php`

- [ ] **Step 1: Write failing Feature tests**

Create `tests/Feature/Api/ActivatePlanTest.php`:

```php
<?php

use App\Models\Core\Tenant;
use App\Models\User;
use App\Services\TenantTokenService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('platform can activate starter plan and old token is rejected', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'slug' => 'feat-activate-starter',
        'commercial_status' => 'demo',
        'plan_slug' => 'demo',
        'messages_monthly_limit' => 100,
        'demo_expires_at' => now()->addDays(10),
    ]);

    $oldPlain = app(TenantTokenService::class)->issue($tenant, 'cliente-demo')->plainTextToken;

    $response = $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.activate-plan', $tenant->public_id), [
            'planSlug' => 'starter',
            'billingCycle' => 'monthly',
            'orderExternalRef' => '01JXORDERFEAT1',
            'tokenName' => 'cliente-paid-starter',
        ], idempotencyHeaders());

    $response->assertCreated()
        ->assertJsonPath('plan.slug', 'starter')
        ->assertJsonPath('plan.messagesMonthlyLimit', 5000)
        ->assertJsonPath('tenant.commercialStatus', 'active')
        ->assertJsonPath('tenant.planSlug', 'starter')
        ->assertJsonStructure(['tenant' => ['publicId'], 'token', 'plan']);

    $newToken = $response->json('token');
    expect($newToken)->toBeString()->not->toBeEmpty();
    expect(PersonalAccessToken::findToken($oldPlain))->toBeNull();

    $this->app['auth']->forgetGuards();

    $this->withToken($newToken)
        ->postJson(route('api.v1.account.status'), [])
        ->assertOk()
        ->assertJsonPath('commercialStatus', 'active')
        ->assertJsonPath('plan.slug', 'starter');
});

test('tenant token cannot activate plan', function () {
    $tenant = Tenant::factory()->create(['slug' => 'feat-activate-denied']);
    $user = User::factory()->forTenant($tenant)->create();
    $user->givePermissionTo('tenants.gestionar');
    $tenantToken = $user->createToken('tenant', ['tenants.gestionar'])->plainTextToken;

    $this->withToken($tenantToken)
        ->postJson(route('api.v1.tenants.activate-plan', $tenant->public_id), [
            'planSlug' => 'starter',
            'billingCycle' => 'monthly',
            'orderExternalRef' => '01JXORDERDENY1',
        ], idempotencyHeaders())
        ->assertForbidden();
});

test('starter rejects client-supplied messagesMonthlyLimit with 422', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create(['slug' => 'feat-activate-no-override']);

    $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.activate-plan', $tenant->public_id), [
            'planSlug' => 'starter',
            'billingCycle' => 'monthly',
            'orderExternalRef' => '01JXORDERNOOV1',
            'messagesMonthlyLimit' => 999999,
        ], idempotencyHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['messagesMonthlyLimit']);
});

test('empresa accepts custom messagesMonthlyLimit', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create(['slug' => 'feat-activate-empresa']);

    $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.activate-plan', $tenant->public_id), [
            'planSlug' => 'empresa',
            'billingCycle' => 'annual',
            'orderExternalRef' => '01JXORDEREF1',
            'messagesMonthlyLimit' => 250000,
        ], idempotencyHeaders())
        ->assertCreated()
        ->assertJsonPath('plan.messagesMonthlyLimit', 250000)
        ->assertJsonPath('tenant.messagesMonthlyLimit', 250000);
});

test('idempotency key replay returns same body', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create(['slug' => 'feat-activate-idem-key']);
    $headers = idempotencyHeaders();

    $payload = [
        'planSlug' => 'business',
        'billingCycle' => 'monthly',
        'orderExternalRef' => '01JXORDERIDEMKEY1',
    ];

    $first = $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.activate-plan', $tenant->public_id), $payload, $headers)
        ->assertCreated();

    $second = $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.activate-plan', $tenant->public_id), $payload, $headers)
        ->assertCreated();

    expect($second->json())->toEqual($first->json());
});
```

Note on starter+override: FormRequest must `prohibited_unless:planSlug,empresa` (or custom rule) so non-empresa overrides 422 before service runs. Unit service still ignores override for safety if somehow called — Feature enforces 422.

Adjust `ActivatePlanService::resolveMessagesMonthlyLimit` path: for Feature “starter rejects override”, validation is in FormRequest; service unit already ignores override for starter — keep FormRequest as source of HTTP 422. Update Task 4 unit test is already “ignores”; Feature asserts 422 via request rules.

- [ ] **Step 2: Run — expect FAIL**

```bash
php artisan test tests/Feature/Api/ActivatePlanTest.php
```

Expected: FAIL — route `api.v1.tenants.activate-plan` missing.

- [ ] **Step 3: FormRequest + controller + route**

Create `app/Http/Requests/Api/V1/ActivatePlanRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $slugs = array_keys(config('plans.catalog', []));

        return [
            'planSlug' => ['required', 'string', Rule::in($slugs)],
            'billingCycle' => ['required', 'string', Rule::in(['monthly', 'annual'])],
            'orderExternalRef' => ['required', 'string', 'max:100'],
            'messagesMonthlyLimit' => [
                'nullable',
                'integer',
                'prohibited_unless:planSlug,empresa',
                'required_if:planSlug,empresa',
                'min:'.(int) config('plans.empresa.messages_monthly_limit_min', 1000),
                'max:'.(int) config('plans.empresa.messages_monthly_limit_max', 10_000_000),
            ],
            'tokenName' => ['nullable', 'string', 'max:100'],
        ];
    }
}
```

Update `TenantController` constructor to inject `ActivatePlanService`, add method:

```php
use App\Http\Requests\Api\V1\ActivatePlanRequest;
use App\Services\ActivatePlanService;

// constructor:
public function __construct(
    private readonly TenantProvisioningService $provisioningService,
    private readonly TenantTokenService $tenantTokenService,
    private readonly ActivatePlanService $activatePlanService,
) {}

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
```

In `routes/api.php`, after the tokens route:

```php
    Route::post('/tenants/{tenant:public_id}/activate-plan', [TenantController::class, 'activatePlan'])
        ->middleware('permission:tenants.gestionar')
        ->name('api.v1.tenants.activate-plan');
```

- [ ] **Step 4: Run Feature tests — expect PASS**

```bash
php artisan test tests/Feature/Api/ActivatePlanTest.php
```

Expected: PASS (all tests).

If starter-override assertion fails because service is hit first, ensure FormRequest runs (it does for typed request). If Laravel uses `prohibited_unless` and still passes null — send integer as in test.

- [ ] **Step 5: Commit (suggested)**

```bash
git add app/Http/Requests/Api/V1/ActivatePlanRequest.php app/Http/Controllers/Api/V1/TenantController.php routes/api.php tests/Feature/Api/ActivatePlanTest.php
git commit -m "feat(api): platform activate-plan endpoint with token reissue"
```

---

### Task 6: Plan-aware send rates (HTTP + job)

**Files:**
- Create: `app/Support/PlanRateResolver.php`
- Create: `tests/Unit/Support/PlanRateResolverTest.php`
- Create: `tests/Feature/Api/PlanAwareMessageSendThrottleTest.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Jobs/TransactionalMessageJob.php`

- [ ] **Step 1: Failing unit + Feature throttle tests**

Create `tests/Unit/Support/PlanRateResolverTest.php`:

```php
<?php

use App\Models\Core\Tenant;
use App\Support\PlanRateResolver;

test('resolver uses starter http and job rates', function () {
    $tenant = Tenant::factory()->make(['plan_slug' => 'starter']);

    expect(PlanRateResolver::httpSendPerMinute($tenant))->toBe(30)
        ->and(PlanRateResolver::jobSendPerMinute($tenant))->toBe(60);
});

test('resolver falls back to demo when slug missing', function () {
    $tenant = Tenant::factory()->make(['plan_slug' => null]);

    expect(PlanRateResolver::httpSendPerMinute($tenant))->toBe(10)
        ->and(PlanRateResolver::jobSendPerMinute($tenant))->toBe(30);
});
```

Create `tests/Feature/Api/PlanAwareMessageSendThrottleTest.php`:

```php
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
```

- [ ] **Step 2: Run — expect FAIL**

```bash
php artisan test tests/Unit/Support/PlanRateResolverTest.php tests/Feature/Api/PlanAwareMessageSendThrottleTest.php
```

Expected: FAIL — resolver missing and/or limit still 10 for starter.

- [ ] **Step 3: Implement resolver + wire rates**

Create `app/Support/PlanRateResolver.php`:

```php
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
```

In `app/Providers/AppServiceProvider.php`, replace the `messages-send` limiter:

```php
use App\Models\Core\Tenant;
use App\Support\PlanRateResolver;

        RateLimiter::for('messages-send', function (Request $request) {
            $user = $request->user();
            $key = $user
                ? 'tenant:'.($user->tenant_id ?? 'platform').':user:'.$user->id
                : 'ip:'.$request->ip();

            $tenant = null;
            if ($user?->tenant_id) {
                $tenant = Tenant::query()->find($user->tenant_id);
            }

            $perMinute = PlanRateResolver::httpSendPerMinute($tenant);

            return Limit::perMinute($perMinute)->by($key);
        });
```

In `TransactionalMessageJob::middleware()`, replace the hardcoded `maxAttempts: 30` with plan-aware resolution (keep `RateLimitedWithRedis`):

```php
    public function middleware(): array
    {
        $mensaje = Mensaje::query()->withoutGlobalScope('tenant')->find($this->mensajeId);
        $tenantKey = $mensaje?->tenant_id ?? 'unknown';
        $tenant = $mensaje?->tenant_id
            ? Tenant::query()->find($mensaje->tenant_id)
            : null;
        $maxAttempts = \App\Support\PlanRateResolver::jobSendPerMinute($tenant);

        return [
            new RateLimitedWithRedis("green-api:tenant:{$tenantKey}", maxAttempts: $maxAttempts, decaySeconds: 60),
        ];
    }
```

Ensure `use App\Models\Core\Tenant` remains imported in the job file.

- [ ] **Step 4: Run — expect PASS**

```bash
php artisan test tests/Unit/Support/PlanRateResolverTest.php tests/Feature/Api/PlanAwareMessageSendThrottleTest.php
```

Expected: PASS.

Optional job unit smoke (same PR if time): assert middleware maxAttempts for a business tenant — skip if Feature+unit cover HTTP/catalog; job path is a one-line wired call.

- [ ] **Step 5: Commit (suggested)**

```bash
git add app/Support/PlanRateResolver.php app/Providers/AppServiceProvider.php app/Jobs/TransactionalMessageJob.php tests/Unit/Support/PlanRateResolverTest.php tests/Feature/Api/PlanAwareMessageSendThrottleTest.php
git commit -m "feat(plans): make message send HTTP and job throttles plan-aware"
```

---

### Task 7: Contract documentation

**Files:**
- Modify: `docs/integration/waapi-api-contract.md`

- [ ] **Step 1: Insert activate-plan section after `POST /tenants/{publicId}/tokens`**

Insert before `### POST /instances` the following section (copy verbatim into the contract file):

~~~~markdown
---

### `POST /tenants/{publicId}/activate-plan`

> **Estado implementación (api):** **Planificado** → implementar con `docs/superpowers/plans/2026-07-14-plan-activation-and-package-limits.md`. Tras merge, marcar **Implementado**.

**Permiso:** `tenants.gestionar`  
**Acceso:** solo cuenta de plataforma (`ensurePlatformService`)  
**Idempotency-Key:** requerido  

Activa un plan de pago canónico (catálogo en `config/plans.php`) sobre el **mismo tenant/instancia** (Hybrid A). Actualiza cuota mensual y `commercialStatus=active`, limpia `demoExpiresAt`, guarda auditoría en `meta` (`billing_cycle`, `activated_order_ref`, `activated_at`), **revoca** tokens del api-client del tenant y emite uno nuevo.

**Preferir este endpoint** frente a `PATCH /tenants/{publicId}` al autorizar cobro: un PATCH sin revoke deja válidos tokens demo filtrados.

**Body:**

```json
{
  "planSlug": "starter",
  "billingCycle": "monthly",
  "orderExternalRef": "01JXORDER…",
  "messagesMonthlyLimit": null,
  "tokenName": "cliente-paid-starter"
}
```

| Campo | Tipo | Reglas |
|-------|------|--------|
| `planSlug` | string | requerido; `demo` \| `starter` \| `business` \| `empresa` |
| `billingCycle` | string | `monthly` \| `annual` |
| `orderExternalRef` | string | requerido; id de orden Framework (auditoría) |
| `messagesMonthlyLimit` | int\|null | **solo** si `planSlug=empresa` (min/max en config); prohibido en otros slugs |
| `tokenName` | string | opcional; default `cliente-{slug}` |

**Respuesta 201** (primera activación):

```json
{
  "tenant": { "publicId": "…", "commercialStatus": "active", "planSlug": "starter", "messagesMonthlyLimit": 5000 },
  "token": "17|…",
  "plan": {
    "slug": "starter",
    "name": "Starter",
    "messagesMonthlyLimit": 5000,
    "billingCycle": "monthly"
  }
}
```

**Respuesta 200:** misma forma cuando ya está `active` con el mismo `planSlug` + `orderExternalRef` (`token` será `null` — no se reemite). Replay con el mismo `Idempotency-Key` reutiliza la respuesta cacheada (incluido el token de la primera llamada).

**Errores:** `403` no-plataforma · `404` tenant · `422` slug/override inválido.

**Rate limits por plan (mismo delivery):** el limiter HTTP `messages-send` y el throttle Redis del job `TransactionalMessageJob` leen rates de `config/plans.php` según `plan_slug` del tenant (fallback `demo`).
~~~~

Also add one line under `### PATCH /tenants/{publicId}`:

```markdown
> Para desbloqueo pago (demo → plan), usar `POST …/activate-plan` (revoke + token nuevo). `PATCH` comercial sin rotar tokens queda desaconsejado.
```

- [ ] **Step 2: Skim consistency**

Confirm sample TenantResource fields match `TenantResource` (`commercialStatus`, `planSlug`, `messagesMonthlyLimit`). Do not invent columns.

- [ ] **Step 3: Commit (suggested)**

```bash
git add docs/integration/waapi-api-contract.md
git commit -m "docs(contract): document activate-plan and plan-aware send rates"
```

---

### Task 8: Full regression suite

**Files:** none new

- [ ] **Step 1: Run focused + broader API tests**

```bash
php artisan test tests/Unit/Support/PlanCatalogTest.php tests/Unit/Support/PlanRateResolverTest.php tests/Unit/Services/ActivatePlanServiceTest.php tests/Feature/Api/ActivatePlanTest.php tests/Feature/Api/PlanAwareMessageSendThrottleTest.php tests/Feature/Api/TenantTokenTest.php tests/Feature/Api/TenantProvisioningTest.php tests/Feature/Api/AccountStatusTest.php
```

Expected: all PASS.

- [ ] **Step 2: Optional full suite**

```bash
composer test
```

Expected: PASS (or only pre-existing failures unrelated — do not expand scope).

- [ ] **Step 3: Commit only if Task 7 left docs dirty or fixes landed** — otherwise skip empty commit.

---

## Self-review (spec coverage)

| Spec requirement | Task |
|------------------|------|
| Canonical catalog `config/plans.php` | Task 1 |
| `meta.billing_cycle` / order / activated_at | Tasks 2 + 4 |
| `POST …/activate-plan` platform + `tenants.gestionar` + Idempotency-Key | Task 5 |
| Body fields + empresa-only override | Tasks 4–5 |
| Effects: status, plan, quota, clear demo_expires_at, revoke, issue, 201 envelope | Tasks 4–5 |
| Tenant cannot activate / cannot escalate via override | Task 5 |
| Idempotent Idempotency-Key + same orderRef | Tasks 4–5 |
| Prefer activate-plan over bare PATCH (docs) | Task 7 |
| Plan-aware HTTP + job rates | Task 6 |
| Contract sync | Task 7 |
| Quota still AccountStatus/MessageSendService | Non-goal — no change |
| Framework `LebytekApiClient::activatePlan` | Companion repo — out of scope here |

**Placeholder scan:** none intentional.  
**Type consistency:** `planSlug` / `billingCycle` / `orderExternalRef` / `messagesMonthlyLimit` / `tokenName` used end-to-end; response keys `tenant` / `token` / `plan` with plan camelCase `messagesMonthlyLimit` + `billingCycle`.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-14-plan-activation-and-package-limits.md`.

Ship order for Framework: merge/deploy this api endpoint before enabling production Authorize that calls it.
