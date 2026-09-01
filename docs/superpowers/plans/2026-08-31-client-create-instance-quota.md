# Client Create Instance + Plan Quota — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow tenant Bearer tokens with `instancias.crear` to `POST /api/v1/instances` under a persisted per-tenant `max_instances` cupo (422 upgrade when full), and expose `instances.used` / `instances.limit` on `POST /account/status`.

**Architecture:** Extend `config/plans.php` + `PlanCatalog::resolveMaxInstances`. Persist cupo on `core_tenants.max_instances` (demo provision + activate-plan). Enforce in `InstanceProvisioningService::provision` via `InstanceQuotaExceededException` → HTTP 422. Relax `InstanceController::store` to use `resolveTenantAccess` (same as GET). Add `instancias.crear` to client abilities allowlists. Extend `AccountStatusService` payload. Update contract docs; docsV2/Portal are PR notes only.

**Tech Stack:** Laravel 13, Sanctum, Pest, Spatie permissions, existing Green provisioning jobs

**Spec:** [`docs/superpowers/specs/2026-08-31-client-create-instance-quota-design.md`](../specs/2026-08-31-client-create-instance-quota-design.md)

## Global Constraints

- Quota exceeded → HTTP **422** with exact message: `Has alcanzado el límite de instancias WhatsApp de tu plan. Mejora tu cuenta para generar otra instancia.`
- Missing ability `instancias.crear` → HTTP **403** (middleware; do not change)
- Same cupo for platform and tenant Bearer — **no platform bypass**
- Count: Eloquent default (excludes soft-deleted); **failed counts** until delete or same-`externalRef` retry
- Idempotent `externalRef` hit / failed retry → **do not** apply quota rejection as a “new create”
- `empresa` catalog `max_instances` = `null` (unlimited) unless activate-plan sends `maxInstances` override
- Do not edit `vendor/`
- Do not implement Portal UI or docsV2 sandbox in this plan — document follow-ups in contract/PR notes
- Never name Green API in client-facing upgrade copy
- **TDD:** failing test before production code in each code task
- **Commits:** suggested messages below; only commit when the user asks (or when the executor has explicit commit approval)

---

## File map

| File | Action | Responsibility |
|------|--------|----------------|
| `config/plans.php` | Modify | Add `max_instances` per catalog slug |
| `app/Support/PlanCatalog.php` | Modify | `resolveMaxInstances(string $slug, ?int $override): ?int` |
| `tests/Unit/Support/PlanCatalogTest.php` | Modify | Unit coverage for max_instances |
| `database/migrations/2026_08_31_000001_add_max_instances_to_core_tenants_table.php` | Create | Column + backfill from catalog |
| `app/Models/Core/Tenant.php` | Modify | fillable + cast `max_instances` |
| `app/Services/TenantProvisioningService.php` | Modify | Set `max_instances` on demo commercial attrs |
| `app/Services/ActivatePlanService.php` | Modify | Persist resolved `max_instances` on activate |
| `app/Http/Requests/Api/V1/ActivatePlanRequest.php` | Modify | Optional `maxInstances` for `empresa` |
| `app/Exceptions/InstanceQuotaExceededException.php` | Create | Domain exception |
| `bootstrap/app.php` | Modify | Render exception as JSON 422 |
| `app/Services/GreenApi/InstanceProvisioningService.php` | Modify | Quota guard before new create |
| `config/permissions.php` | Modify | Add `instancias.crear` to `demo_client_abilities` |
| `app/Http/Requests/Api/V1/StoreTenantTokenRequest.php` | Modify | Allowlist `instancias.crear` |
| `app/Http/Controllers/Api/V1/InstanceController.php` | Modify | Drop platform-only gate on `store`; use `resolveTenantAccess`; purpose default |
| `app/Services/AccountStatusService.php` | Modify | Add `instances.used` / `instances.limit` |
| `tests/Feature/Api/InstanceProvisioningTest.php` | Modify | Client create + quota + flip old “cannot create” |
| `tests/Feature/Api/AccountStatusTest.php` | Modify | Assert instances block |
| `tests/Feature/Api/ActivatePlanTest.php` | Modify | Assert `max_instances` persisted (+ empresa override) |
| `docs/integration/waapi-api-contract.md` | Modify | POST /instances + account/status + abilities note |
| Spec status header | Modify | Mark implemented when done (optional last step) |

---

### Task 1: Plan catalog `max_instances` + `PlanCatalog::resolveMaxInstances`

**Files:**
- Modify: `config/plans.php`
- Modify: `app/Support/PlanCatalog.php`
- Modify: `tests/Unit/Support/PlanCatalogTest.php`

**Interfaces:**
- Consumes: existing `PlanCatalog::definition(string $slug): ?array`
- Produces: `PlanCatalog::resolveMaxInstances(string $slug, ?int $override): ?int`  
  - unknown slug → `InvalidArgumentException`  
  - non-`empresa` → catalog value; **ignore** `$override`  
  - `empresa` + `$override === null` → `null` (unlimited)  
  - `empresa` + `$override < 1` → `InvalidArgumentException`  
  - `empresa` + `$override >= 1` → that int

- [ ] **Step 1: Write the failing unit tests**

Append to `tests/Unit/Support/PlanCatalogTest.php`:

```php
test('catalog includes max_instances per slug', function () {
    expect(PlanCatalog::definition('demo')['max_instances'])->toBe(1)
        ->and(PlanCatalog::definition('starter')['max_instances'])->toBe(1)
        ->and(PlanCatalog::definition('business')['max_instances'])->toBe(3)
        ->and(PlanCatalog::definition('empresa')['max_instances'])->toBeNull();
});

test('resolveMaxInstances returns catalog values and ignores override for starter', function () {
    expect(PlanCatalog::resolveMaxInstances('starter', 99))->toBe(1)
        ->and(PlanCatalog::resolveMaxInstances('business', null))->toBe(3);
});

test('resolveMaxInstances allows unlimited empresa without override', function () {
    expect(PlanCatalog::resolveMaxInstances('empresa', null))->toBeNull();
});

test('resolveMaxInstances accepts empresa override', function () {
    expect(PlanCatalog::resolveMaxInstances('empresa', 10))->toBe(10);
});

test('resolveMaxInstances rejects empresa override below one', function () {
    expect(fn () => PlanCatalog::resolveMaxInstances('empresa', 0))
        ->toThrow(InvalidArgumentException::class);
});
```

Add `use InvalidArgumentException;` at top if not present (or use fully-qualified as existing tests do).

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
cd /Users/ingbrandonmtz/whatsapi && php artisan test --compact tests/Unit/Support/PlanCatalogTest.php
```

Expected: FAIL — missing `max_instances` keys and/or `resolveMaxInstances` undefined.

- [ ] **Step 3: Minimal implementation**

In `config/plans.php`, add to each catalog entry:

```php
'demo' => [
    'name' => 'Demo',
    'messages_monthly_limit' => 100,
    'max_instances' => 1,
    'http_send_per_minute' => 10,
    'job_send_per_minute' => 30,
],
'starter' => [
    'name' => 'Starter',
    'messages_monthly_limit' => 5000,
    'max_instances' => 1,
    'http_send_per_minute' => 30,
    'job_send_per_minute' => 60,
],
'business' => [
    'name' => 'Business',
    'messages_monthly_limit' => 80000,
    'max_instances' => 3,
    'http_send_per_minute' => 60,
    'job_send_per_minute' => 120,
],
'empresa' => [
    'name' => 'Enterprise',
    'messages_monthly_limit' => null,
    'max_instances' => null,
    'http_send_per_minute' => 120,
    'job_send_per_minute' => 180,
],
```

In `app/Support/PlanCatalog.php`, update the `@return` phpdoc on `definition` to include `max_instances: int|null`, and add:

```php
public static function resolveMaxInstances(string $slug, ?int $override): ?int
{
    $plan = self::definition($slug);

    if ($plan === null) {
        throw new InvalidArgumentException("Unknown plan slug [{$slug}].");
    }

    if ($slug === 'empresa') {
        if ($override === null) {
            return null;
        }

        if ($override < 1) {
            throw new InvalidArgumentException('maxInstances must be at least 1 for empresa.');
        }

        return $override;
    }

    return $plan['max_instances'];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run:

```bash
cd /Users/ingbrandonmtz/whatsapi && php artisan test --compact tests/Unit/Support/PlanCatalogTest.php
```

Expected: PASS

- [ ] **Step 5: Commit (when approved)**

```bash
git add config/plans.php app/Support/PlanCatalog.php tests/Unit/Support/PlanCatalogTest.php
git commit -m "$(cat <<'EOF'
feat: add max_instances to plan catalog

Resolve per-slug WhatsApp instance caps via PlanCatalog for
quota enforcement on instance create.
EOF
)"
```

---

### Task 2: Persist `max_instances` on tenants (migration + demo + activate-plan)

**Files:**
- Create: `database/migrations/2026_08_31_000001_add_max_instances_to_core_tenants_table.php`
- Modify: `app/Models/Core/Tenant.php`
- Modify: `app/Services/TenantProvisioningService.php`
- Modify: `app/Services/ActivatePlanService.php`
- Modify: `app/Http/Requests/Api/V1/ActivatePlanRequest.php`
- Modify: `tests/Feature/Api/ActivatePlanTest.php`

**Interfaces:**
- Consumes: `PlanCatalog::resolveMaxInstances`
- Produces: `Tenant::$max_instances` (`?int`, fillable, cast integer); demo provision sets `1`; activate-plan writes resolved value; `ActivatePlanRequest` accepts optional `maxInstances` only when `planSlug=empresa`

- [ ] **Step 1: Write the failing activate-plan assertions**

In `tests/Feature/Api/ActivatePlanTest.php`, extend the happy-path starter activate test (the one that already activates `starter`) to also assert:

```php
$tenant->refresh();
expect($tenant->max_instances)->toBe(1);
```

Add a new test (or extend empresa test) for override:

```php
test('activate empresa can set maxInstances override', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'commercial_status' => 'demo',
        'plan_slug' => 'demo',
    ]);

    $this->withToken($token)
        ->postJson(route('api.v1.tenants.activate-plan', $tenant->public_id), [
            'planSlug' => 'empresa',
            'billingCycle' => 'monthly',
            'orderExternalRef' => 'ord_empresa_max_inst',
            'messagesMonthlyLimit' => 250000,
            'maxInstances' => 5,
        ], idempotencyHeaders())
        ->assertOk();

    expect($tenant->fresh()->max_instances)->toBe(5);
});
```

(Match existing activate-plan payload/header patterns in that file — keep `assertSuccessful`/`assertOk` consistent with siblings.)

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
cd /Users/ingbrandonmtz/whatsapi && php artisan test --compact tests/Feature/Api/ActivatePlanTest.php --filter='max_instances|maxInstances|starter'
```

Expected: FAIL — unknown column / attribute null / not 1.

- [ ] **Step 3: Migration + model + writers**

Create migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_tenants', function (Blueprint $table) {
            $table->unsignedInteger('max_instances')->nullable()->after('messages_monthly_limit');
        });

        $catalog = config('plans.catalog', []);

        foreach ($catalog as $slug => $plan) {
            $max = $plan['max_instances'] ?? null;
            DB::table('core_tenants')
                ->where('plan_slug', $slug)
                ->update(['max_instances' => $max]);
        }

        // Tenants without plan_slug: treat as demo cupo
        DB::table('core_tenants')
            ->whereNull('plan_slug')
            ->orWhere('plan_slug', '')
            ->update(['max_instances' => $catalog['demo']['max_instances'] ?? 1]);
    }

    public function down(): void
    {
        Schema::table('core_tenants', function (Blueprint $table) {
            $table->dropColumn('max_instances');
        });
    }
};
```

Update `Tenant` `#[Fillable([...])]` to include `'max_instances'`, and casts:

```php
'max_instances' => 'integer',
```

In `TenantProvisioningService::demoCommercialAttributes()`, add:

```php
'max_instances' => PlanCatalog::resolveMaxInstances($demoSlug, null),
```

(import `App\Support\PlanCatalog` if needed; prefer `PlanCatalog` over raw config).

In `ActivatePlanRequest::rules()`, add:

```php
'maxInstances' => [
    'nullable',
    'integer',
    'prohibited_unless:planSlug,empresa',
    'min:1',
],
```

In `ActivatePlanService::activate`:

1. Resolve after messages limit:

```php
try {
    $maxInstances = PlanCatalog::resolveMaxInstances(
        $slug,
        isset($data['maxInstances']) ? (int) $data['maxInstances'] : null,
    );
} catch (InvalidArgumentException $e) {
    throw ValidationException::withMessages([
        'maxInstances' => [$e->getMessage()],
    ]);
}
```

2. Include in `forceFill`:

```php
'max_instances' => $maxInstances,
```

3. Update phpdoc `$data` shape to include `maxInstances?: int|null`.

- [ ] **Step 4: Migrate + run tests**

Run:

```bash
cd /Users/ingbrandonmtz/whatsapi && php artisan migrate --force && php artisan test --compact tests/Feature/Api/ActivatePlanTest.php
```

Expected: PASS (full file).

- [ ] **Step 5: Commit (when approved)**

```bash
git add database/migrations/2026_08_31_000001_add_max_instances_to_core_tenants_table.php \
  app/Models/Core/Tenant.php \
  app/Services/TenantProvisioningService.php \
  app/Services/ActivatePlanService.php \
  app/Http/Requests/Api/V1/ActivatePlanRequest.php \
  tests/Feature/Api/ActivatePlanTest.php
git commit -m "$(cat <<'EOF'
feat: persist tenant max_instances from plan catalog

Add column, backfill, demo defaults, and activate-plan override
for empresa so quota guards read a durable cupo.
EOF
)"
```

---

### Task 3: Quota guard + 422 exception

**Files:**
- Create: `app/Exceptions/InstanceQuotaExceededException.php`
- Modify: `bootstrap/app.php`
- Modify: `app/Services/GreenApi/InstanceProvisioningService.php`
- Modify: `tests/Feature/Api/InstanceProvisioningTest.php`

**Interfaces:**
- Consumes: `$tenant->max_instances`; `Instancia` soft-delete aware count
- Produces: `InstanceQuotaExceededException` with fixed Spanish message; thrown only on **new** create path; rendered as `{ "message": "…" }` with status **422**

Exact message constant (use in exception + tests):

```text
Has alcanzado el límite de instancias WhatsApp de tu plan. Mejora tu cuenta para generar otra instancia.
```

- [ ] **Step 1: Write failing feature tests for quota**

Append to `tests/Feature/Api/InstanceProvisioningTest.php`:

```php
test('platform create is rejected with 422 when instance quota is exhausted', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'plan_slug' => 'starter',
        'max_instances' => 1,
    ]);
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);
    Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
    ]);

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Second',
            'purpose' => 'production',
        ], idempotencyHeaders())
        ->assertStatus(422)
        ->assertJsonPath(
            'message',
            'Has alcanzado el límite de instancias WhatsApp de tu plan. Mejora tu cuenta para generar otra instancia.'
        );

    expect(Instancia::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(1);
    Bus::assertNotDispatched(ProvisionGreenInstanceJob::class);
});

test('idempotent externalRef replay does not hit quota when already at limit', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'plan_slug' => 'starter',
        'max_instances' => 1,
    ]);
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);
    $existing = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'external_ref' => 'same_ref',
        'status' => 'authorized',
    ]);

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Demo Acme',
            'externalRef' => 'same_ref',
        ], idempotencyHeaders())
        ->assertOk()
        ->assertJsonPath('publicId', $existing->public_id);

    expect(Instancia::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('null max_instances means unlimited', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'plan_slug' => 'empresa',
        'max_instances' => null,
    ]);
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);
    Instancia::factory()->create(['tenant_id' => $tenant->id, 'status' => 'authorized']);

    $this->withToken($token)
        ->withHeader('X-Tenant-Id', $tenant->public_id)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Another',
        ], idempotencyHeaders())
        ->assertAccepted();

    Bus::assertDispatched(ProvisionGreenInstanceJob::class);
});
```

Note: the exhausted-quota test uses `Bus::fake` from `beforeEach`, so `assertNotDispatched` is valid only if no prior dispatch in that test — correct here. For the unlimited test, one prior factory instance exists but no job until POST — OK.

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
cd /Users/ingbrandonmtz/whatsapi && php artisan test --compact tests/Feature/Api/InstanceProvisioningTest.php --filter='quota|idempotent externalRef replay|unlimited'
```

Expected: FAIL — second create still 202 / wrong status / jobs dispatched.

- [ ] **Step 3: Implement exception, render, guard**

Create `app/Exceptions/InstanceQuotaExceededException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class InstanceQuotaExceededException extends RuntimeException
{
    public const MESSAGE = 'Has alcanzado el límite de instancias WhatsApp de tu plan. Mejora tu cuenta para generar otra instancia.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
```

In `bootstrap/app.php`, import and register render (alongside `GreenApiException`):

```php
use App\Exceptions\InstanceQuotaExceededException;

$exceptions->render(function (InstanceQuotaExceededException $e, Request $request) {
    if (! $request->is('api/*')) {
        return null;
    }

    return response()->json(['message' => $e->getMessage()], 422);
});
```

In `InstanceProvisioningService::provision`, after module guard and **after** the existing `externalRef` short-circuit block, **before** `DB::transaction` create:

```php
$this->ensureInstanceQuotaAvailable($tenant);

// ... then create as today
```

Add private method:

```php
private function ensureInstanceQuotaAvailable(Tenant $tenant): void
{
    $limit = $tenant->max_instances;

    if ($limit === null) {
        return;
    }

    $used = Instancia::query()
        ->withoutGlobalScope('tenant')
        ->where('tenant_id', $tenant->id)
        ->count();

    if ($used >= $limit) {
        throw new InstanceQuotaExceededException;
    }
}
```

Import `App\Exceptions\InstanceQuotaExceededException`.

- [ ] **Step 4: Run tests to verify they pass**

Run:

```bash
cd /Users/ingbrandonmtz/whatsapi && php artisan test --compact tests/Feature/Api/InstanceProvisioningTest.php
```

Expected: PASS for new tests; existing platform create still PASS (factory tenants have `max_instances` null → unlimited unless set).

- [ ] **Step 5: Commit (when approved)**

```bash
git add app/Exceptions/InstanceQuotaExceededException.php bootstrap/app.php \
  app/Services/GreenApi/InstanceProvisioningService.php \
  tests/Feature/Api/InstanceProvisioningTest.php
git commit -m "$(cat <<'EOF'
feat: enforce WhatsApp instance plan quota on provision

Reject new creates with 422 upgrade message when tenant
max_instances is reached; keep externalRef idempotency.
EOF
)"
```

---

### Task 4: Client Bearer auth for `POST /instances` + abilities

**Files:**
- Modify: `config/permissions.php`
- Modify: `app/Http/Requests/Api/V1/StoreTenantTokenRequest.php`
- Modify: `app/Http/Controllers/Api/V1/InstanceController.php`
- Modify: `tests/Feature/Api/InstanceProvisioningTest.php`

**Interfaces:**
- Consumes: `resolveTenantAccess(Request): int` (already private on controller)
- Produces: `store` no longer calls `ensurePlatformService`; uses `$this->resolveTenantAccess($request)` for tenant id; optional `purpose` defaults to `production` when acting tenant `commercial_status === 'active'`, else `demo`; `demo_client_abilities` includes `instancias.crear`

- [ ] **Step 1: Write failing / replace tests for client create**

Replace the existing test `tenant token can read own instances but not create` with two tests:

```php
test('tenant token without instancias.crear cannot create', function () {
    $tenant = Tenant::factory()->create([
        'max_instances' => 3,
        'plan_slug' => 'business',
    ]);
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo(['instancias.ver']);
    $clientToken = $client->createToken('client', ['instancias.ver'])->plainTextToken;

    $this->withToken($clientToken)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Blocked',
        ], idempotencyHeaders())
        ->assertForbidden();
});

test('tenant token with instancias.crear can create second instance under business cupo', function () {
    $tenant = Tenant::factory()->create([
        'commercial_status' => 'active',
        'plan_slug' => 'business',
        'max_instances' => 3,
    ]);
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);
    Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo(['instancias.ver', 'instancias.crear']);
    $clientToken = $client->createToken('client', ['instancias.ver', 'instancias.crear'])->plainTextToken;

    $this->withToken($clientToken)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'WhatsApp Sucursal 2',
            'purpose' => 'production',
        ], idempotencyHeaders())
        ->assertAccepted()
        ->assertJsonPath('label', 'WhatsApp Sucursal 2')
        ->assertJsonPath('status', 'provisioning');

    Bus::assertDispatched(ProvisionGreenInstanceJob::class);
    expect(
        Instancia::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count()
    )->toBe(2);
});

test('tenant token on starter at cupo gets 422 upgrade message', function () {
    $tenant = Tenant::factory()->create([
        'commercial_status' => 'active',
        'plan_slug' => 'starter',
        'max_instances' => 1,
    ]);
    Module::factory()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'whatsapp',
        'is_enabled' => true,
    ]);
    Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo(['instancias.crear']);
    $clientToken = $client->createToken('client', ['instancias.crear'])->plainTextToken;

    $this->withToken($clientToken)
        ->postJson(route('api.v1.instances.store'), [
            'label' => 'Should fail',
        ], idempotencyHeaders())
        ->assertStatus(422)
        ->assertJsonPath(
            'message',
            'Has alcanzado el límite de instancias WhatsApp de tu plan. Mejora tu cuenta para generar otra instancia.'
        );

    expect(
        Instancia::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count()
    )->toBe(1);
    Bus::assertNotDispatched(ProvisionGreenInstanceJob::class);
});
```

Keep an explicit platform-within-cupo assertion if not already covered by the first test — the existing `platform service can create instance…` remains the platform happy path (ensure its tenant has `max_instances` null or ≥1).

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
cd /Users/ingbrandonmtz/whatsapi && php artisan test --compact tests/Feature/Api/InstanceProvisioningTest.php --filter='tenant token'
```

Expected: FAIL — client with `instancias.crear` still 403 from `ensurePlatformService`.

- [ ] **Step 3: Implement auth + abilities**

`config/permissions.php` — `demo_client_abilities`:

```php
'demo_client_abilities' => [
    'instancias.ver',
    'instancias.crear',
    'mensajes.enviar',
    'mensajes.ver',
    'cuenta.ver',
],
```

`StoreTenantTokenRequest` allowlist — add `'instancias.crear'`.

`InstanceController::store`:

```php
public function store(StoreInstanceRequest $request): JsonResponse
{
    $tenantId = $this->resolveTenantAccess($request);
    $validated = $request->validated();

    $purpose = $validated['purpose'] ?? null;
    if ($purpose === null) {
        $tenant = \App\Models\Core\Tenant::query()->findOrFail($tenantId);
        $purpose = $tenant->commercial_status === 'active' ? 'production' : 'demo';
    }

    $result = $this->provisioningService->provision($tenantId, [
        'label' => $validated['label'],
        'externalRef' => $validated['externalRef'] ?? null,
        'purpose' => $purpose,
    ]);

    $async = ($result['created'] ?? false) || ($result['retried'] ?? false);

    return (new InstanceResource($result['instancia']))
        ->response()
        ->setStatusCode($async ? 202 : 200);
}
```

Do **not** call `ensurePlatformService` here. Prefer a proper `use App\Models\Core\Tenant;` import instead of FQCN.

Ensure Spatie: tests that `givePermissionTo('instancias.crear')` work because `RolesAndPermissionsSeeder` already seeds `instancias.crear` in nucleo permissions (verify if a test fails — seeder already includes it from exploration).

- [ ] **Step 4: Run full instance + related token tests**

Run:

```bash
cd /Users/ingbrandonmtz/whatsapi && php artisan test --compact tests/Feature/Api/InstanceProvisioningTest.php tests/Feature/Api/ActivatePlanTest.php tests/Feature/Api/TenantTokenTest.php
```

Expected: PASS. If `TenantTokenTest` asserts exact ability list, update expectations to include `instancias.crear`.

- [ ] **Step 5: Commit (when approved)**

```bash
git add config/permissions.php app/Http/Requests/Api/V1/StoreTenantTokenRequest.php \
  app/Http/Controllers/Api/V1/InstanceController.php \
  tests/Feature/Api/InstanceProvisioningTest.php \
  tests/Feature/Api/TenantTokenTest.php
git commit -m "$(cat <<'EOF'
feat: allow tenant Bearer to create instances within cupo

Drop platform-only store gate, grant instancias.crear on
client abilities, and keep RBAC 403 for legacy tokens.
EOF
)"
```

---

### Task 5: `account/status` exposes `instances.used` / `instances.limit`

**Files:**
- Modify: `app/Services/AccountStatusService.php`
- Modify: `tests/Feature/Api/AccountStatusTest.php`

**Interfaces:**
- Consumes: `$tenant->max_instances`; same count query as quota guard
- Produces: payload key `instances` => `['used' => int, 'limit' => int|null]`

- [ ] **Step 1: Write failing test**

Update `tests/Feature/Api/AccountStatusTest.php` demo status test — set `max_instances` on tenant and create one instancia; assert:

```php
use App\Models\Integration\Instancia;

// in factory create array:
'max_instances' => 1,

// after tenant create:
Instancia::factory()->create([
    'tenant_id' => $tenant->id,
    'status' => 'authorized',
]);

// assertions:
->assertJsonPath('instances.used', 1)
->assertJsonPath('instances.limit', 1)

// extend assertJsonStructure:
'instances' => ['used', 'limit'],
```

Add a second small test for unlimited:

```php
test('account status reports null instance limit when unlimited', function () {
    $tenant = Tenant::factory()->create([
        'commercial_status' => 'active',
        'plan_slug' => 'empresa',
        'plan_name' => 'Enterprise',
        'messages_monthly_limit' => 250000,
        'max_instances' => null,
    ]);
    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo(['cuenta.ver']);
    $token = $client->createToken('client', ['cuenta.ver'])->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.account.status'), [])
        ->assertOk()
        ->assertJsonPath('instances.used', 0)
        ->assertJsonPath('instances.limit', null);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
cd /Users/ingbrandonmtz/whatsapi && php artisan test --compact tests/Feature/Api/AccountStatusTest.php
```

Expected: FAIL — missing `instances` key.

- [ ] **Step 3: Implement**

In `AccountStatusService::buildStatus`, import `Instancia` and add:

```php
$instancesUsed = Instancia::query()
    ->withoutGlobalScope('tenant')
    ->where('tenant_id', $tenant->id)
    ->count();

// in return array:
'instances' => [
    'used' => $instancesUsed,
    'limit' => $tenant->max_instances,
],
```

- [ ] **Step 4: Run tests**

Run:

```bash
cd /Users/ingbrandonmtz/whatsapi && php artisan test --compact tests/Feature/Api/AccountStatusTest.php
```

Expected: PASS

- [ ] **Step 5: Commit (when approved)**

```bash
git add app/Services/AccountStatusService.php tests/Feature/Api/AccountStatusTest.php
git commit -m "$(cat <<'EOF'
feat: expose instances used/limit on account status

Surface plan cupo for sandbox and future Portal upgrade UX.
EOF
)"
```

---

### Task 6: Contract docs + PR follow-up checklist

**Files:**
- Modify: `docs/integration/waapi-api-contract.md` (section `### POST /instances` ~382–402; also `account/status` section if present)
- Optionally update spec header status in `docs/superpowers/specs/2026-08-31-client-create-instance-quota-design.md`

**Interfaces:** none (docs only)

- [ ] **Step 1: Locate account/status section and rewrite POST /instances**

Replace `### POST /instances` block with content equivalent to:

```markdown
### `POST /instances`

**Estado:** **Implementado**  
**Permiso:** `instancias.crear`  
**Acceso:**
- Token **por-tenant** (Bearer cliente) — confinado a su tenant; **no** requiere `X-Tenant-Id`
- Token **plataforma** + header `X-Tenant-Id: {tenantPublicId}`
**Idempotency-Key:** requerido  

**Cupo (`max_instances` en `core_tenants`):**

| planSlug | default max_instances |
|----------|----------------------|
| demo | 1 |
| starter | 1 |
| business | 3 |
| empresa | `null` (ilimitado; override opcional `maxInstances` en activate-plan) |

Si `count(instancias no soft-deleted) >= max_instances` (y el límite no es `null`) → **422**:

```json
{
  "message": "Has alcanzado el límite de instancias WhatsApp de tu plan. Mejora tu cuenta para generar otra instancia."
}
```

Misma regla para plataforma y cliente (sin bypass). Idempotencia por `externalRef` / retry de `failed` no consume cupo adicional.

**Body:**

```json
{
  "label": "WhatsApp Sucursal 2",
  "externalRef": "opcional-idempotente",
  "purpose": "production"
}
```

`purpose`: `demo` \| `production`. Si se omite: `production` cuando `commercial_status=active`, si no `demo`.

**Abilities:** tokens cliente nuevos (`demo_client_abilities` / activate-plan / issue token) incluyen `instancias.crear`. Tokens emitidos **antes** de este cambio carecen de la ability → **403** hasta reemisión.

**Respuesta:** `202` provisioning (async) o `200` cuando idempotente/existente.

Also implemented: `GET /instances`, `GET /instances/{publicId}`, `GET /instances/{publicId}/qr`, `DELETE /instances/{publicId}` (DELETE sigue plataforma).

**Follow-up (fuera de este repo en esta entrega):**
- **docsV2:** allowlist POST `/instances` (+ opcional `account/status`), `createInstance`, sandbox UI used/limit, OpenAPI/Postman.
- **Portal:** mostrar `instances.used`/`limit`; no duplicar enforcement (API es SoT).
```

Find the `account/status` section in the same file and document:

```markdown
"instances": { "used": 1, "limit": 3 }
```

(`limit` may be `null` when unlimited.)

- [ ] **Step 2: No automated test for markdown** — skip RED. Optionally run scribe if annotations exist on controller; do **not** sync docsV2 in this task (note in PR).

- [ ] **Step 3: Regression suite for this feature**

Run:

```bash
cd /Users/ingbrandonmtz/whatsapi && php artisan test --compact \
  tests/Unit/Support/PlanCatalogTest.php \
  tests/Feature/Api/InstanceProvisioningTest.php \
  tests/Feature/Api/AccountStatusTest.php \
  tests/Feature/Api/ActivatePlanTest.php
```

Expected: all PASS.

- [ ] **Step 4: Commit (when approved)**

```bash
git add docs/integration/waapi-api-contract.md docs/superpowers/specs/2026-08-31-client-create-instance-quota-design.md
git commit -m "$(cat <<'EOF'
docs: contract for client instance create and quotas

Document Bearer client POST /instances, 422 upgrade, cupos,
account/status instances block, and docsV2/Portal follow-ups.
EOF
)"
```

---

## Spec coverage checklist (self-review)

| Spec requirement | Task |
|------------------|------|
| Catalog `max_instances` + PlanCatalog | Task 1 |
| Column + backfill + demo/activate persist | Task 2 |
| Guard in provision; failed counts; idempotency exempt | Task 3 |
| 422 upgrade message | Task 3 |
| Client create auth; abilities; platform same cupo | Task 4 |
| account/status used/limit | Task 5 |
| Contract + docsV2/Portal notes | Task 6 |
| Feature tests listed in spec | Tasks 3–5 |
| Unit PlanCatalog | Task 1 |
| Non-goals Portal/docsV2 code | Out of plan (documented Task 6) |

**Placeholder scan:** none intentional.  
**Type consistency:** `resolveMaxInstances(string, ?int): ?int`; tenant column `max_instances`; JSON `instances.limit` nullable; exception message constant shared.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-08-31-client-create-instance-quota.md`.

**Two execution options:**

1. **Subagent-Driven (recommended)** — fresh subagent per task, review between tasks  
2. **Inline Execution** — execute tasks in this session with checkpoints  

Which approach?
