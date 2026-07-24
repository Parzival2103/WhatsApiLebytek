# Admin Leads Message Usage Progress Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show a high-priority “Uso mensajes” progress bar (`sent / limit`) on admin MKT leads list by adding platform-scoped tenant usage APIs and enriching CRUD rows via a batch call.

**Architecture:** WhatsApiLebytek exposes `GET /tenants/{publicId}/usage` and `POST /tenants/usage` (batch) reusing `AccountStatusService` monthly outbound counts. Lebytek_Framework adds virtual CRUD columns, `format: progress` + safe `_html` cells, an `afterListRows` hook, and an `mkt_leads` handler that calls `LebytekApiClient::getTenantsUsage` once per page.

**Tech Stack:** Laravel 13 / Pest (api); Lebytek Framework CRUD Engine (`src/`) + app Marketing / `LebytekApiClient` (Framework); Bootstrap progress bars in admin CRUD view.

**Spec:** [`docs/superpowers/specs/2026-07-23-admin-leads-message-usage-progress-design.md`](../specs/2026-07-23-admin-leads-message-usage-progress-design.md)

## Global Constraints

- Two repos, sequential: finish **api Tasks 1–4** before Framework Tasks that call the live endpoint; Framework platform (`src/`) can proceed in parallel with api once interfaces are locked by this plan.
- Do **not** merge Framework `feature/backoffice-api-integration` → `main`.
- Do **not** break `last_activity_at`, `POST /account/status`, `GET /usage`, or HTML escaping for normal CRUD cells.
- Monthly counts = outbound `queued`+`sent` in calendar month via `AccountStatusService` (same as quota).
- **Do not** inject or call `TenantUsageService` (Redis metering) — monthly usage is **only** `AccountStatusService`.
- Batch: `publicIds` min 1, max 100, unique; unknown IDs omitted (no 404 for whole request).
- `publicId` values are **ULIDs** (`Tenant.public_id`); validate with Laravel `ulid` (spec wording “UUID” means publicId string, not UUID v4).
- Semáforo: verde `<70%`, amarillo `70–89%`, rojo `≥90%`; `sent > limit` → bar 100%, text real, `danger`.
- Missing tenant / `limit === null` / API error → cell `—`; list must still return 200.
- Progress HTML built only from integers + Bootstrap class whitelist (`success`|`warning`|`danger`); never trust inbound HTML.
- Cache Redis / writing `last_activity_at` / portal waapi UI = out of scope.
- Commits: api commits in `WhatsApiLebytek`; Framework commits in `Lebytek_Framework` (separate git roots).

## Audit fixes (2026-07-23)

Plan audited against live code. Apply these before / while executing:

| Sev | Area | Fix |
|-----|------|-----|
| Critical | Task 3 tests | Every `postJson` must pass `idempotencyHeaders()` (v1 group always runs `ApiIdempotencyKey` → 422 without header). |
| Critical | Task 3 tests | Unknown / synthetic IDs must be real ULIDs (`(string) Str::ulid()`). Strings like `01UNKNOWN…` / `01TEST%022d` fail `ulid` validation (Crockford / length). |
| High | Task 2 vs 3 | Task 2 ships **only** `show` + GET route. Task 3 adds `batch`, FormRequest rules, and POST route. Do not register POST with empty `rules()`. |
| High | Naming | Prefer `TenantUsageController` but document: never wire `App\Services\TenantUsageService`. |
| High | Task 5 tests | Do **not** mirror `CrudConfigValidatorRelationsTest` (static helpers only). Extract pure `listColumnSchemaErrors(...)` **or** use a real-DB integration test. Also test DataService SELECT skip. |
| High | Task 9 client | List enrich must use short timeout + `maxRetries: 1` (not provisioning defaults 30s×3 — hangs admin list). |
| Medium | GET auth | Keep platform **or** same-tenant (mirror `TenantController::show`); design §4.1 “platform-only” wording is relaxed to match. |
| Medium | Task 7 | `beforeListQuery` is **not** wired anywhere — `afterListRows` is the first list call site in `CrudResourceService`. Wiring test is **required**. |
| Medium | Virtual columns | Reject `virtual` + `searchable`/`sortable`; exclude virtual from `CrudResourceDefinition::columnNames()`. |
| Medium | Docs | Task 4: note these endpoints are **not** `GET /usage` (lifetime totals / `mensajes.ver`). Client must mint a **new** Idempotency-Key per batch POST (24h cache). |

## File structure

### WhatsApiLebytek (api)

| File | Responsibility |
|------|----------------|
| `app/Services/AccountStatusService.php` | Add `buildUsagePayload(Tenant): array`; optionally `buildUsagePayloadMap` for batch |
| `app/Http/Controllers/Api/V1/TenantUsageController.php` | `show` + `batch` |
| `app/Http/Requests/Api/V1/TenantUsageBatchRequest.php` | Validate `publicIds` |
| `routes/api.php` | Register usage routes (`permission:tenants.ver`) |
| `tests/Feature/Api/TenantUsageTest.php` | Feature coverage |
| `docs/integration/waapi-api-contract.md` | Document both endpoints |

### Lebytek_Framework (platform `src/` + app)

| File | Responsibility |
|------|----------------|
| `src/Application/Services/CrudConfigValidator.php` | Allow `virtual: true` list columns; reject virtual+searchable/sortable |
| `src/Application/Services/CrudDataService.php` | Omit virtual columns from SQL SELECT / search |
| `src/Domain/Entities/CrudResourceDefinition.php` | Exclude virtual from `columnNames()` |
| `src/Application/Services/CrudTableBuilder.php` | `format === 'progress'` → `_formatted` + `_html` |
| `src/Presentation/Views/admin/crud/index.php` | Echo `_html[$name]` without `ViewHelper::e` |
| `src/Application/Crud/Context/CrudListRowsContext.php` | Mutable page rows for enrich |
| `src/Application/Crud/Handlers/AbstractCrudHookHandler.php` | `afterListRows` no-op |
| `src/Domain/Interfaces/CrudHookHandlerInterface.php` | Docblock for extended hook (no interface method) |
| `src/Application/Services/CrudResourceService.php` | Invoke `afterListRows` after list, before `tableBuilder->build` |
| `src/Kernel/Container/FrameworkServiceProvider.php` | Inject `CrudHookRunner` into `CrudResourceService` (11th arg) |
| `app/Infrastructure/Integrations/LebytekApi/LebytekApiClient.php` | `getTenantsUsage` (+ optional `getTenantUsage`) |
| `app/Application/Marketing/MktLeadsCrudHookHandler.php` | Enrich `messages_usage` (fast fail timeout) |
| `config/crud_handlers.php` | Register `mkt_leads` |
| `config/cruds/mkt_leads.json` | Virtual progress column + handler key |
| `tests/Crud/Config/CrudConfigValidatorVirtualColumnTest.php` | Virtual schema helper |
| `tests/Crud/Data/CrudDataServiceVirtualSelectTest.php` | SELECT omit virtual |
| `tests/Crud/Table/CrudTableBuilderProgressTest.php` | Progress formatting |
| `tests/Crud/Context/CrudListRowsContextTest.php` | Context mutability |
| `tests/Crud/CrudResourceServiceAfterListRowsTest.php` | Hook wiring required |
| `tests/Marketing/MktLeadsCrudHookHandlerTest.php` | Enrich + API failure → `—` |
| `tests/Integration/LebytekApiClientTest.php` | Client path/body |
| `docs/integration/waapi-api-contract.md` | Mirror api contract |

---

### Task 1: Shared usage payload on AccountStatusService (api)

**Files:**
- Modify: `app/Services/AccountStatusService.php`
- Test: covered by Task 2–3 Feature tests (no separate unit file required)

**Interfaces:**
- Produces:
  - `AccountStatusService::buildUsagePayload(Tenant $tenant): array` returning:
    ```php
    [
      'messagesSentThisMonth' => int,
      'messagesLimitThisMonth' => ?int,
      'messagesRemainingThisMonth' => ?int,
    ]
    ```
  - `AccountStatusService::buildUsagePayloadMap(iterable $tenants): array` — map keyed by `public_id` string → same payload shape (uses one grouped COUNT for efficiency)

- [ ] **Step 1: Add payload helpers and refactor `buildStatus`**

In `AccountStatusService`, replace the inline usage block with helpers:

```php
/**
 * @return array{
 *   messagesSentThisMonth: int,
 *   messagesLimitThisMonth: int|null,
 *   messagesRemainingThisMonth: int|null
 * }
 */
public function buildUsagePayload(Tenant $tenant): array
{
    $messagesSent = $this->countMessagesSentThisMonth($tenant->id);
    $limit = $tenant->messages_monthly_limit;
    $messagesRemaining = $limit !== null ? max(0, $limit - $messagesSent) : null;

    return [
        'messagesSentThisMonth' => $messagesSent,
        'messagesLimitThisMonth' => $limit,
        'messagesRemainingThisMonth' => $messagesRemaining,
    ];
}

/**
 * @param  iterable<int, Tenant>  $tenants
 * @return array<string, array{
 *   messagesSentThisMonth: int,
 *   messagesLimitThisMonth: int|null,
 *   messagesRemainingThisMonth: int|null
 * }>
 */
public function buildUsagePayloadMap(iterable $tenants): array
{
    $tenants = collect($tenants)->values();
    if ($tenants->isEmpty()) {
        return [];
    }

    $tenantIds = $tenants->pluck('id')->all();
    $start = Carbon::now()->startOfMonth();
    $end = Carbon::now()->endOfMonth();

    $counts = Mensaje::query()
        ->withoutGlobalScope('tenant')
        ->selectRaw('tenant_id, COUNT(*) as aggregate')
        ->whereIn('tenant_id', $tenantIds)
        ->where('direction', 'outbound')
        ->whereIn('status', ['queued', 'sent'])
        ->whereBetween('created_at', [$start, $end])
        ->groupBy('tenant_id')
        ->pluck('aggregate', 'tenant_id');

    $map = [];
    foreach ($tenants as $tenant) {
        $sent = (int) ($counts[$tenant->id] ?? 0);
        $limit = $tenant->messages_monthly_limit;
        $map[$tenant->public_id] = [
            'messagesSentThisMonth' => $sent,
            'messagesLimitThisMonth' => $limit,
            'messagesRemainingThisMonth' => $limit !== null ? max(0, $limit - $sent) : null,
        ];
    }

    return $map;
}
```

In `buildStatus`, set `'usage' => $this->buildUsagePayload($tenant)`.

Keep `countMessagesSentThisMonth` unchanged (still used by `MessageSendService`).

- [ ] **Step 2: Commit (WhatsApiLebytek)**

```bash
git add app/Services/AccountStatusService.php
git commit -m "$(cat <<'EOF'
refactor: extract tenant usage payload helpers for platform endpoints

EOF
)"
```

---

### Task 2: `GET /api/v1/tenants/{publicId}/usage` (api)

**Files:**
- Create: `app/Http/Controllers/Api/V1/TenantUsageController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/TenantUsageTest.php`

**Interfaces:**
- Consumes: `AccountStatusService::buildUsagePayload`
- Produces: route `api.v1.tenants.usage.show`; JSON body = usage payload (flat, not nested under `usage`)

- [ ] **Step 1: Write the failing Feature tests**

Create `tests/Feature/Api/TenantUsageTest.php`:

```php
<?php

use App\Models\Core\Tenant;
use App\Models\Integration\Mensaje;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('GET tenant usage requires authentication', function () {
    $tenant = Tenant::factory()->create(['messages_monthly_limit' => 100]);

    $this->getJson(route('api.v1.tenants.usage.show', $tenant->public_id))
        ->assertUnauthorized();
});

test('platform service can get tenant usage by public id', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create(['messages_monthly_limit' => 100]);

    Mensaje::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'direction' => 'outbound',
        'status' => 'sent',
    ]);
    Mensaje::factory()->create([
        'tenant_id' => $tenant->id,
        'direction' => 'outbound',
        'status' => 'failed',
    ]);

    $this->withToken($token)
        ->getJson(route('api.v1.tenants.usage.show', $tenant->public_id))
        ->assertOk()
        ->assertExactJson([
            'messagesSentThisMonth' => 3,
            'messagesLimitThisMonth' => 100,
            'messagesRemainingThisMonth' => 97,
        ]);
});

test('GET tenant usage returns null remaining when limit is null', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create(['messages_monthly_limit' => null]);

    $this->withToken($token)
        ->getJson(route('api.v1.tenants.usage.show', $tenant->public_id))
        ->assertOk()
        ->assertJsonPath('messagesLimitThisMonth', null)
        ->assertJsonPath('messagesRemainingThisMonth', null);
});

test('GET tenant usage forbidden without tenants.ver', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->platformAdmin()->create();
    // no permissions synced
    $token = $user->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.tenants.usage.show', $tenant->public_id))
        ->assertForbidden();
});

test('GET tenant usage 404 for unknown public id', function () {
    $token = platformServiceToken();
    $missing = (string) \Illuminate\Support\Str::ulid();

    $this->withToken($token)
        ->getJson(route('api.v1.tenants.usage.show', $missing))
        ->assertNotFound();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run (from WhatsApiLebytek root):

```bash
php artisan test --filter=TenantUsageTest
```

Expected: FAIL (route / controller missing).

- [ ] **Step 3: Implement controller + route (GET only — no batch yet)**

Create `app/Http/Controllers/Api/V1/TenantUsageController.php` with **only** `show` + `ensureTenantAccess`.
Do **not** add `batch`, FormRequest, or `POST /tenants/usage` here (Task 3).
Inject `AccountStatusService` only — **never** `TenantUsageService`.

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\Tenant;
use App\Services\AccountStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantUsageController extends Controller
{
    public function __construct(
        private readonly AccountStatusService $accountStatusService,
    ) {}

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenant);

        return response()->json(
            $this->accountStatusService->buildUsagePayload($tenant)
        );
    }

    /** Mirror TenantController::show — platform admin or same-tenant user. */
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
```

In `routes/api.php`, add GET usage next to `tenants.show` (no POST yet):

```php
use App\Http\Controllers\Api\V1\TenantUsageController;

Route::get('/tenants/{tenant:public_id}/usage', [TenantUsageController::class, 'show'])
    ->middleware('permission:tenants.ver')
    ->withoutMiddleware('api.idempotency')
    ->name('api.v1.tenants.usage.show');
```

- [ ] **Step 4: Run GET tests — expect PASS**

```bash
php artisan test --filter='GET tenant usage'
```

Expected: PASS for all GET cases.

- [ ] **Step 5: Commit (WhatsApiLebytek)**

```bash
git add app/Http/Controllers/Api/V1/TenantUsageController.php routes/api.php tests/Feature/Api/TenantUsageTest.php
git commit -m "$(cat <<'EOF'
feat: add platform GET tenant monthly message usage

EOF
)"
```

---

### Task 3: `POST /api/v1/tenants/usage` batch (api)

**Files:**
- Create/Modify: `app/Http/Requests/Api/V1/TenantUsageBatchRequest.php`
- Modify: `app/Http/Controllers/Api/V1/TenantUsageController.php` (add `batch` if not already)
- Modify: `routes/api.php` (batch route if not already)
- Modify: `tests/Feature/Api/TenantUsageTest.php`

**Interfaces:**
- Consumes: `buildUsagePayloadMap`
- Produces: `api.v1.tenants.usage.batch` → `{ "items": { "<publicId>": { ... } } }`

- [ ] **Step 1: Write failing batch tests** (append to `TenantUsageTest.php`)

**Required:** every `postJson` third argument = `idempotencyHeaders()` (same as `TenantProvisioningTest`). Without it, middleware returns 422 before the controller.

```php
use Illuminate\Support\Str;

test('POST tenants usage batch returns map for known public ids', function () {
    $token = platformServiceToken();
    $a = Tenant::factory()->create(['messages_monthly_limit' => 100]);
    $b = Tenant::factory()->create(['messages_monthly_limit' => null]);
    Mensaje::factory()->count(2)->create([
        'tenant_id' => $a->id,
        'direction' => 'outbound',
        'status' => 'queued',
    ]);

    // Must be a valid Crockford ULID that is not persisted (not "01UNKNOWN…").
    $unknown = (string) Str::ulid();

    $this->withToken($token)
        ->postJson(route('api.v1.tenants.usage.batch'), [
            'publicIds' => [$a->public_id, $b->public_id, $unknown],
        ], idempotencyHeaders())
        ->assertOk()
        ->assertJsonPath("items.{$a->public_id}.messagesSentThisMonth", 2)
        ->assertJsonPath("items.{$a->public_id}.messagesLimitThisMonth", 100)
        ->assertJsonPath("items.{$b->public_id}.messagesLimitThisMonth", null)
        ->assertJsonMissingPath("items.{$unknown}");
});

test('POST tenants usage batch validates publicIds bounds', function () {
    $token = platformServiceToken();

    $this->withToken($token)
        ->postJson(route('api.v1.tenants.usage.batch'), ['publicIds' => []], idempotencyHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['publicIds']);

    $tooMany = array_map(
        fn () => (string) Str::ulid(),
        range(1, 101)
    );

    $this->withToken($token)
        ->postJson(route('api.v1.tenants.usage.batch'), ['publicIds' => $tooMany], idempotencyHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['publicIds']);
});

test('POST tenants usage batch requires platform service', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $user->givePermissionTo(['tenants.ver']);
    $token = $user->createToken('t')->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.tenants.usage.batch'), [
            'publicIds' => [$tenant->public_id],
        ], idempotencyHeaders())
        ->assertForbidden();
});
```

- [ ] **Step 2: Run batch tests — expect FAIL**

```bash
php artisan test --filter='POST tenants usage'
```

- [ ] **Step 3: Implement request + batch action + route**

`TenantUsageBatchRequest`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class TenantUsageBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'publicIds' => ['required', 'array', 'min:1', 'max:100'],
            'publicIds.*' => ['required', 'string', 'ulid', 'distinct'],
        ];
    }
}
```

Add `batch` + `ensurePlatformService` to `TenantUsageController`. In `routes/api.php`, register **batch before** parameterized tenant routes:

```php
Route::post('/tenants/usage', [TenantUsageController::class, 'batch'])
    ->middleware('permission:tenants.ver')
    ->name('api.v1.tenants.usage.batch');
```

Client always sends `Idempotency-Key` on POST — leave idempotency middleware enabled.
**Ops note:** successful responses are cached ~24h per user+key; `LebytekApiClient` must mint a **new** key per enrich call (it already does for POSTs).

```php
public function batch(TenantUsageBatchRequest $request): JsonResponse
{
    $this->ensurePlatformService($request);

    $publicIds = array_values(array_unique($request->validated('publicIds')));
    $tenants = Tenant::query()->whereIn('public_id', $publicIds)->get();

    return response()->json([
        'items' => $this->accountStatusService->buildUsagePayloadMap($tenants),
    ]);
}

private function ensurePlatformService(Request $request): void
{
    if (! $request->user()?->isPlatformAdmin()) {
        abort(403, 'Platform service access required.');
    }
}
```

- [ ] **Step 4: Run full TenantUsageTest — expect PASS**

```bash
php artisan test --filter=TenantUsageTest
```

Expected: all PASS. Also smoke existing account status:

```bash
php artisan test --filter=AccountStatusTest
```

Expected: PASS (usage helpers must not change tenant-token contract).

- [ ] **Step 5: Commit (WhatsApiLebytek)**

```bash
git add app/Http/Requests/Api/V1/TenantUsageBatchRequest.php app/Http/Controllers/Api/V1/TenantUsageController.php routes/api.php tests/Feature/Api/TenantUsageTest.php
git commit -m "$(cat <<'EOF'
feat: add platform batch tenant monthly message usage

EOF
)"
```

---

### Task 4: Document usage endpoints in api contract

**Files:**
- Modify: `docs/integration/waapi-api-contract.md` (after `GET /tenants/{publicId}` section ~L200)

**Interfaces:**
- Produces: contract text matching Tasks 2–3

- [ ] **Step 1: Insert contract sections**

After `### GET /tenants/{publicId}`, insert the following two endpoint sections (match tone/tables of neighboring tenant docs).

**Not the same as `GET /usage`:** that endpoint is acting-tenant lifetime totals under `mensajes.ver`. These new paths are monthly quota (`queued`+`sent` calendar month) under `tenants.ver`.

**`GET /tenants/{publicId}/usage`**

- Permiso: `tenants.ver`
- Acceso: plataforma (cualquier tenant) **o** usuario del mismo tenant (mismo criterio que `GET /tenants/{publicId}`)
- Idempotency-Key: no aplica (GET)
- Semántica: cuota mensual del mes calendario (outbound `queued`+`sent`), misma regla que `POST /account/status` → `usage`
- Respuesta 200 body:

```json
{
  "messagesSentThisMonth": 20,
  "messagesLimitThisMonth": 100,
  "messagesRemainingThisMonth": 80
}
```

- Si límite null: `messagesLimitThisMonth` y `messagesRemainingThisMonth` son `null`
- Errores: `404` tenant inexistente; `403` sin permiso / acceso denegado

**`POST /tenants/usage`**

- Permiso: `tenants.ver`; acceso solo plataforma
- Idempotency-Key: requerido (el cliente HTTP lo envía; operación de solo lectura). **Mint a new key per call** — responses are idempotency-cached.
- Body: `{ "publicIds": ["01JXYZ...", "01JABC..."] }`
- Reglas: `publicIds` array string, 1–100, **ULID**, distintos; IDs desconocidos omitidos del map
- Respuesta 200:

```json
{
  "items": {
    "01JXYZ...": {
      "messagesSentThisMonth": 20,
      "messagesLimitThisMonth": 100,
      "messagesRemainingThisMonth": 80
    }
  }
}
```

- [ ] **Step 2: Commit (WhatsApiLebytek)**

```bash
git add docs/integration/waapi-api-contract.md
git commit -m "$(cat <<'EOF'
docs: contract for tenant usage GET and batch endpoints

EOF
)"
```

---

### Task 5: Virtual list columns (Framework `src/`)

**Files:**
- Modify: `src/Application/Services/CrudConfigValidator.php` (~L84–98)
- Modify: `src/Application/Services/CrudDataService.php` (~L46–54 select; ~L71–90 searchable)
- Modify: `src/Domain/Entities/CrudResourceDefinition.php` (`columnNames()` — exclude virtual)
- Create: `tests/Crud/Config/CrudConfigValidatorVirtualColumnTest.php`
- Create: `tests/Crud/Data/CrudDataServiceVirtualSelectTest.php` (or pure helper test for select filter)

**Interfaces:**
- Produces: list columns with `"virtual": true` skip schema existence check and SQL SELECT
- Guard: `virtual: true` must not combine with `searchable: true` or `sortable: true`
- `columnNames()` omit virtual so filters/reports cannot SELECT them

- [ ] **Step 1: Write failing tests (pure helper — do not mirror RelationsTest)**

`CrudConfigValidatorRelationsTest` only covers **static** helpers; instance `validate()` needs a real DB / `GenericCrudRepository` (final — no subclass fake). Prefer extracting a pure helper:

```php
// In CrudConfigValidator (public static or package-visible):
/**
 * @param  list<array<string, mixed>>  $columns
 * @param  array<string, true>  $columnLookup
 * @return list<string>
 */
public static function listColumnSchemaErrors(array $columns, array $columnLookup, string $table): array
```

Move the list.columns loop body into that helper (including virtual skip + virtual×searchable/sortable rejection). Unit-test without DB:

```php
test('virtual list column skips schema existence check', function (): void {
    $errors = CrudConfigValidator::listColumnSchemaErrors(
        [['name' => 'messages_usage', 'virtual' => true]],
        ['id' => true],
        'dom_mkt_leads'
    );
    assert_same([], $errors);
});

test('non-virtual missing column errors', function (): void {
    $errors = CrudConfigValidator::listColumnSchemaErrors(
        [['name' => 'messages_usage']],
        ['id' => true],
        'dom_mkt_leads'
    );
    assert_true(count($errors) >= 1);
});

test('virtual + searchable is rejected', function (): void {
    $errors = CrudConfigValidator::listColumnSchemaErrors(
        [['name' => 'messages_usage', 'virtual' => true, 'searchable' => true]],
        ['id' => true],
        'dom_mkt_leads'
    );
    assert_true(count($errors) >= 1);
});
```

Also add a **select-filter** unit assertion (same filter logic as DataService) that `messages_usage` with `virtual: true` is **not** in select names — shipping validator-only green while forgetting DataService still 500s the list in Task 10.

- [ ] **Step 2: Run test — expect FAIL**

```bash
php tests/run.php CrudConfigValidatorVirtual
php tests/run.php CrudDataServiceVirtual
```

- [ ] **Step 3: Implement virtual skip in validator + DataService + columnNames**

In the list.columns helper / loop:

```php
$isVirtual = !empty($column['virtual']);
if ($isVirtual && (!empty($column['searchable']) || !empty($column['sortable']))) {
    $errors[] = "La columna virtual {$name} no puede ser searchable ni sortable.";
}
if (!$isVirtual && !isset($columnLookup[$name])) {
    $errors[] = "La columna de listado {$name} no existe en {$table}.";
}
```

In `CrudDataService::list`, when building `$selectColumns`:

```php
$selectColumns = array_values(array_unique(array_filter(array_map(
    static function (array $column): string {
        if (!empty($column['virtual'])) {
            return '';
        }
        return (string) ($column['name'] ?? '');
    },
    $columns
))));
```

(Keep appending PK + `deleted` as today.)

In searchable loop, skip virtual columns defensively (`if (!empty($column['virtual'])) continue;`).

In `CrudResourceDefinition::columnNames()`, skip columns with `virtual: true`.

- [ ] **Step 4: Run tests — expect PASS**

- [ ] **Step 5: Commit (Lebytek_Framework)**

```bash
git add src/Application/Services/CrudConfigValidator.php src/Application/Services/CrudDataService.php src/Domain/Entities/CrudResourceDefinition.php tests/Crud/Config/CrudConfigValidatorVirtualColumnTest.php tests/Crud/Data/CrudDataServiceVirtualSelectTest.php
git commit -m "$(cat <<'EOF'
feat(crud): support virtual list columns without DB select

EOF
)"
```

---

### Task 6: `format: progress` + safe `_html` cells (Framework)

**Files:**
- Modify: `src/Application/Services/CrudTableBuilder.php` (`formatRow`)
- Modify: `src/Presentation/Views/admin/crud/index.php` (~L144–157)
- Create: `tests/Crud/Table/CrudTableBuilderProgressTest.php`

**Interfaces:**
- Consumes: row value `messages_usage` as `['sent' => int, 'limit' => int]|null`
- Produces: `_formatted[$name]` text; `_html[$name]` Bootstrap progress HTML when valid

- [ ] **Step 1: Write failing progress tests**

Create `tests/Crud/Table/CrudTableBuilderProgressTest.php`:

```php
<?php

declare(strict_types=1);

use Lebytek\Framework\Application\Services\CrudTableBuilder;

function invokeFormatRow(array $row, array $columns): array
{
    $builder = new CrudTableBuilder();
    $ref = new ReflectionClass($builder);
    $method = $ref->getMethod('formatRow');
    $method->setAccessible(true);

    return $method->invoke($builder, $row, $columns);
}

$progressCol = [
    'name' => 'messages_usage',
    'label' => 'Uso mensajes',
    'format' => 'progress',
    'badge' => [],
];

test('progress: empty or null limit renders em dash without html', function () use ($progressCol): void {
    foreach ([null, [], ['sent' => 1, 'limit' => null], 'x'] as $value) {
        $out = invokeFormatRow(['messages_usage' => $value], [$progressCol]);
        assert_same('—', $out['_formatted']['messages_usage']);
        assert_true(!isset($out['_html']['messages_usage']));
    }
});

test('progress: 20/100 is success ~20%', function () use ($progressCol): void {
    $out = invokeFormatRow(['messages_usage' => ['sent' => 20, 'limit' => 100]], [$progressCol]);
    assert_same('20 / 100', $out['_formatted']['messages_usage']);
    $html = $out['_html']['messages_usage'];
    assert_true(str_contains($html, 'bg-success'));
    assert_true(str_contains($html, 'style="width: 20%"'));
    assert_true(str_contains($html, '20 / 100'));
});

test('progress: 75% warning and 95% danger', function () use ($progressCol): void {
    $w = invokeFormatRow(['messages_usage' => ['sent' => 75, 'limit' => 100]], [$progressCol]);
    assert_true(str_contains($w['_html']['messages_usage'], 'bg-warning'));
    $d = invokeFormatRow(['messages_usage' => ['sent' => 95, 'limit' => 100]], [$progressCol]);
    assert_true(str_contains($d['_html']['messages_usage'], 'bg-danger'));
});

test('progress: sent over limit caps bar at 100% danger with real text', function () use ($progressCol): void {
    $out = invokeFormatRow(['messages_usage' => ['sent' => 120, 'limit' => 100]], [$progressCol]);
    assert_same('120 / 100', $out['_formatted']['messages_usage']);
    $html = $out['_html']['messages_usage'];
    assert_true(str_contains($html, 'bg-danger'));
    assert_true(str_contains($html, 'style="width: 100%"'));
    assert_true(str_contains($html, '120 / 100'));
});
```

- [ ] **Step 2: Run — expect FAIL**

```bash
php tests/run.php CrudTableBuilderProgress
```

- [ ] **Step 3: Implement progress in `formatRow`**

After the `money` branch (before badge handling), add:

```php
if ($format === 'progress') {
    $row['_formatted'][$name] = '—';
    if (!is_array($value)) {
        continue;
    }
    $sent = $value['sent'] ?? null;
    $limit = $value['limit'] ?? null;
    if (!is_int($sent) && !(is_string($sent) && ctype_digit($sent))) {
        continue;
    }
    if ($limit === null || $limit === '' || (!is_int($limit) && !(is_string($limit) && ctype_digit((string) $limit)))) {
        continue;
    }
    $sent = (int) $sent;
    $limit = (int) $limit;
    if ($limit <= 0) {
        continue;
    }

    $pct = (int) min(100, floor(($sent * 100) / $limit));
    if ($pct < 70) {
        $tone = 'success';
    } elseif ($pct < 90) {
        $tone = 'warning';
    } else {
        $tone = 'danger';
    }
    if ($sent > $limit) {
        $pct = 100;
        $tone = 'danger';
    }

    $label = $sent.' / '.$limit;
    $row['_formatted'][$name] = $label;
    // Integers + whitelist only — no user HTML
    $row['_html'][$name] =
        '<div class="progress" style="min-width:7rem;height:1.25rem" role="progressbar" '.
        'aria-valuenow="'.$pct.'" aria-valuemin="0" aria-valuemax="100" '.
        'aria-label="'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'">'.
        '<div class="progress-bar bg-'.$tone.'" style="width: '.$pct.'%">'.
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8').
        '</div></div>';
    continue;
}
```

Initialize `$row['_html'] = [];` next to `_formatted` / `_badge`.

In `index.php` cell loop, keep the existing badge markup; only add an `_html` branch first:

```php
$name = (string) ($column['name'] ?? '');
$value = $row['_formatted'][$name] ?? '';
$badge = $row['_badge'][$name] ?? null;
$html = $row['_html'][$name] ?? null;
?>
<td class="px-3">
    <?php if (is_string($html) && $html !== ''): ?>
        <?= $html ?>
    <?php elseif ($badge !== null): ?>
        <span class="badge rounded-pill bg-<?= ViewHelper::e((string) $badge) ?>-subtle text-<?= ViewHelper::e((string) $badge) ?> border border-<?= ViewHelper::e((string) $badge) ?>-subtle">
            <?= ViewHelper::e((string) $value) ?>
        </span>
    <?php else: ?>
        <?= ViewHelper::e((string) $value) ?>
    <?php endif; ?>
</td>
```

- [ ] **Step 4: Run progress tests — expect PASS**

- [ ] **Step 5: Commit (Lebytek_Framework)**

```bash
git add src/Application/Services/CrudTableBuilder.php src/Presentation/Views/admin/crud/index.php tests/Crud/Table/CrudTableBuilderProgressTest.php
git commit -m "$(cat <<'EOF'
feat(crud): add progress format with safe HTML cells

EOF
)"
```

---

### Task 7: `afterListRows` hook + context (Framework `src/`)

**Files:**
- Create: `src/Application/Crud/Context/CrudListRowsContext.php`
- Modify: `src/Application/Crud/Handlers/AbstractCrudHookHandler.php`
- Modify: `src/Domain/Interfaces/CrudHookHandlerInterface.php` (docblock list of extended hooks only — **do not** add a real interface method)
- Modify: `src/Application/Services/CrudResourceService.php`
- Modify: `src/Kernel/Container/FrameworkServiceProvider.php`
- Create: `tests/Crud/Context/CrudListRowsContextTest.php`
- Create: `tests/Crud/CrudResourceServiceAfterListRowsTest.php` (**required** — not optional)

**Interfaces:**
- Produces: `afterListRows(CrudListRowsContext $ctx): void`
- `CrudListRowsContext::rows(): array` / `setRows(array $rows): void`
- **Note:** `beforeListQuery` exists only as Abstract no-op + phpdoc — it is **not** invoked anywhere. Do **not** look for a call-site pattern to copy; wire `afterListRows` as the **first** list-row hook in `CrudResourceService::buildIndexData`.

- [ ] **Step 1: Write context + wiring tests**

```php
<?php

declare(strict_types=1);

use Lebytek\Framework\Application\Crud\Context\CrudListRowsContext;

test('CrudListRowsContext rows are mutable', function (): void {
    $ctx = new CrudListRowsContext('mkt_leads', 'dom_mkt_leads', 'id', 1, '127.0.0.1', [
        ['id' => 1, 'nombre' => 'A'],
    ]);
    $rows = $ctx->rows();
    $rows[0]['messages_usage'] = ['sent' => 1, 'limit' => 10];
    $ctx->setRows($rows);
    assert_same(1, $ctx->rows()[0]['messages_usage']['sent']);
});
```

Wiring test (**required**): assert that after `dataService->list`, `hookRunner->run(..., 'afterListRows', ...)` runs and that `tableBuilder->build` receives the mutated rows. Use test doubles / reflection as needed — shipping without this test can leave the feature silently unwired.

- [ ] **Step 2: Implement context + abstract no-op**

```php
<?php

declare(strict_types=1);

namespace Lebytek\Framework\Application\Crud\Context;

final class CrudListRowsContext extends CrudContext
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(
        string $resourceKey,
        string $table,
        string $primaryKey,
        ?int $userId,
        string $ip,
        private array $rows,
    ) {
        parent::__construct($resourceKey, $table, $primaryKey, $userId, $ip);
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @param list<array<string, mixed>> $rows */
    public function setRows(array $rows): void
    {
        $this->rows = $rows;
    }
}
```

Add to `AbstractCrudHookHandler` only (not the interface contract methods):

```php
use Lebytek\Framework\Application\Crud\Context\CrudListRowsContext;

public function afterListRows(CrudListRowsContext $ctx): void {}
```

Document `afterListRows(CrudListRowsContext)` in `CrudHookHandlerInterface` phpdoc alongside `beforeListQuery`.

- [ ] **Step 3: Wire hook in `CrudResourceService::buildIndexData`**

Inject `CrudHookRunner $hookRunner` as an **11th** constructor param. Update `FrameworkServiceProvider` binding to pass `$c->get(CrudHookRunner::class)` as the last argument (today the bind has 10 deps — forgetting this fatals boot).

Replace the list→build sequence with:

```php
$result = $this->dataService->list($definition, $query, $userId, $can);

$rowsCtx = new \Lebytek\Framework\Application\Crud\Context\CrudListRowsContext(
    $definition->key(),
    $definition->table(),
    $definition->primaryKey(),
    $userId,
    '',
    is_array($result['rows'] ?? null) ? $result['rows'] : []
);
$this->hookRunner->run($definition, 'afterListRows', $rowsCtx);

$permissions = $this->resolvePermissions($definition->permissionPrefix());

$data = $this->tableBuilder->build(
    definition: $definition,
    rows: $rowsCtx->rows(),
    // ... unchanged args from $result
);
```

**Important:** `CrudHookRunner` rethrows handler exceptions. Enrich handlers must catch API errors internally (Task 9) so the list never 500s.

- [ ] **Step 4: Run context + wiring tests — expect PASS**

- [ ] **Step 5: Commit (Lebytek_Framework)**

```bash
git add src/Application/Crud/Context/CrudListRowsContext.php src/Application/Crud/Handlers/AbstractCrudHookHandler.php src/Domain/Interfaces/CrudHookHandlerInterface.php src/Application/Services/CrudResourceService.php src/Kernel/Container/FrameworkServiceProvider.php tests/Crud/Context/CrudListRowsContextTest.php tests/Crud/CrudResourceServiceAfterListRowsTest.php
git commit -m "$(cat <<'EOF'
feat(crud): add afterListRows hook for list row enrichment

EOF
)"
```

---

### Task 8: `LebytekApiClient::getTenantsUsage` (Framework app)

**Files:**
- Modify: `app/Infrastructure/Integrations/LebytekApi/LebytekApiClient.php`
- Modify: `tests/Integration/LebytekApiClientTest.php`

**Interfaces:**
- Produces:
  - `getTenantsUsage(array $publicIds): array` → decoded `items` map (`array<string, array>`)
  - optional `getTenantUsage(string $publicId): array` → GET single payload
- Chunks input into batches of 100 and merges `items`

- [ ] **Step 1: Write failing client test**

```php
test('LebytekApiClient getTenantsUsage posts publicIds and returns items map', function () {
    $transport = new RecordingTransport();
    $transport->responses[] = [
        'status' => 200,
        'body' => json_encode([
            'items' => [
                '01JTENANTAAAAAAAAAAAAAAA' => [
                    'messagesSentThisMonth' => 20,
                    'messagesLimitThisMonth' => 100,
                    'messagesRemainingThisMonth' => 80,
                ],
            ],
        ], JSON_THROW_ON_ERROR),
        'error' => '',
    ];
    $client = new LebytekApiClient('https://api.test/v1', 'platform-token', 5, 1, $transport);
    $items = $client->getTenantsUsage(['01JTENANTAAAAAAAAAAAAAAA']);
    assert_same(1, count($transport->calls));
    assert_same('POST', $transport->calls[0]['method']);
    assert_true(str_contains($transport->calls[0]['url'], '/tenants/usage'));
    assert_true(str_contains((string) $transport->calls[0]['body'], '01JTENANTAAAAAAAAAAAAAAA'));
    assert_same(20, $items['01JTENANTAAAAAAAAAAAAAAA']['messagesSentThisMonth']);
});

test('LebytekApiClient getTenantsUsage chunks over 100 ids', function () {
    $transport = new RecordingTransport();
    $ids = [];
    for ($i = 0; $i < 101; $i++) {
        $ids[] = sprintf('01CHUNK%020d', $i);
    }
    $transport->responses[] = ['status' => 200, 'body' => '{"items":{}}', 'error' => ''];
    $transport->responses[] = ['status' => 200, 'body' => '{"items":{}}', 'error' => ''];
    $client = new LebytekApiClient('https://api.test/v1', 'tok', 5, 1, $transport);
    $client->getTenantsUsage($ids);
    assert_same(2, count($transport->calls));
});
```

- [ ] **Step 2: Run — expect FAIL**

```bash
php tests/run.php LebytekApiClient
```

- [ ] **Step 3: Implement client methods**

```php
/**
 * @param  list<string>  $publicIds
 * @return array<string, array<string, mixed>>
 */
public function getTenantsUsage(array $publicIds): array
{
    $publicIds = array_values(array_unique(array_filter($publicIds, static fn ($id) => is_string($id) && $id !== '')));
    if ($publicIds === []) {
        return [];
    }

    $items = [];
    foreach (array_chunk($publicIds, 100) as $chunk) {
        $decoded = $this->request('POST', '/tenants/usage', ['publicIds' => $chunk]);
        $chunkItems = $decoded['items'] ?? [];
        if (is_array($chunkItems)) {
            foreach ($chunkItems as $key => $value) {
                if (is_string($key) && is_array($value)) {
                    $items[$key] = $value;
                }
            }
        }
    }

    return $items;
}

/**
 * @return array<string, mixed>
 */
public function getTenantUsage(string $publicId): array
{
    return $this->request('GET', '/tenants/'.$publicId.'/usage');
}
```

- [ ] **Step 4: Run client tests — expect PASS**

- [ ] **Step 5: Commit (Lebytek_Framework)**

```bash
git add app/Infrastructure/Integrations/LebytekApi/LebytekApiClient.php tests/Integration/LebytekApiClientTest.php
git commit -m "$(cat <<'EOF'
feat: LebytekApiClient batch and single tenant usage

EOF
)"
```

---

### Task 9: `MktLeadsCrudHookHandler` enrich (Framework app)

**Files:**
- Create: `app/Application/Marketing/MktLeadsCrudHookHandler.php`
- Create: `tests/Marketing/MktLeadsCrudHookHandlerTest.php`
- Modify: `config/crud_handlers.php`

**Interfaces:**
- Consumes: `CrudListRowsContext`, `LebytekApiClient::getTenantsUsage`
- Produces: each row may set `messages_usage` to `['sent' => int, 'limit' => int]` or leave unset/`null`
- Registry: `'mkt_leads' => \App\Application\Marketing\MktLeadsCrudHookHandler::class`
- `CrudHandlerRegistry` does `new $class()` — constructor must allow zero args; optional client for tests

- [ ] **Step 1: Write failing handler tests**

```php
<?php

declare(strict_types=1);

use App\Application\Marketing\MktLeadsCrudHookHandler;
use App\Infrastructure\Integrations\LebytekApi\LebytekApiClient;
use App\Infrastructure\Integrations\LebytekApi\LebytekApiException;
use App\Infrastructure\Integrations\LebytekApi\LebytekApiTransport;
use Lebytek\Framework\Application\Crud\Context\CrudListRowsContext;

final class UsageRecordingTransport implements LebytekApiTransport
{
    public int $calls = 0;
    public bool $fail = false;

    public function execute(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls++;
        if ($this->fail) {
            return ['status' => 500, 'body' => '{"message":"down"}', 'error' => ''];
        }

        return [
            'status' => 200,
            'body' => json_encode([
                'items' => [
                    '01JTENANTAAAAAAAAAAAAAAA' => [
                        'messagesSentThisMonth' => 20,
                        'messagesLimitThisMonth' => 100,
                        'messagesRemainingThisMonth' => 80,
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'error' => '',
        ];
    }
}

test('mkt leads afterListRows enriches usage and skips empty tenant ids', function (): void {
    $transport = new UsageRecordingTransport();
    $api = new LebytekApiClient('https://api.test/v1', 'tok', 5, 1, $transport);
    $handler = new MktLeadsCrudHookHandler($api);
    $ctx = new CrudListRowsContext('mkt_leads', 'dom_mkt_leads', 'id', 1, '', [
        ['id' => 1, 'api_tenant_public_id' => '01JTENANTAAAAAAAAAAAAAAA'],
        ['id' => 2, 'api_tenant_public_id' => ''],
        ['id' => 3, 'api_tenant_public_id' => null],
    ]);
    $handler->afterListRows($ctx);
    assert_same(1, $transport->calls);
    assert_same(['sent' => 20, 'limit' => 100], $ctx->rows()[0]['messages_usage']);
    assert_true(($ctx->rows()[1]['messages_usage'] ?? null) === null);
    assert_true(($ctx->rows()[2]['messages_usage'] ?? null) === null);
});

test('mkt leads afterListRows leaves null on api failure', function (): void {
    $transport = new UsageRecordingTransport();
    $transport->fail = true;
    $api = new LebytekApiClient('https://api.test/v1', 'tok', 5, 1, $transport);
    $handler = new MktLeadsCrudHookHandler($api);
    $ctx = new CrudListRowsContext('mkt_leads', 'dom_mkt_leads', 'id', 1, '', [
        ['id' => 1, 'api_tenant_public_id' => '01JTENANTAAAAAAAAAAAAAAA'],
    ]);
    $handler->afterListRows($ctx); // must not throw
    assert_true(($ctx->rows()[0]['messages_usage'] ?? null) === null);
});

test('mkt leads afterListRows skips http when no tenant ids', function (): void {
    $transport = new UsageRecordingTransport();
    $api = new LebytekApiClient('https://api.test/v1', 'tok', 5, 1, $transport);
    $handler = new MktLeadsCrudHookHandler($api);
    $ctx = new CrudListRowsContext('mkt_leads', 'dom_mkt_leads', 'id', 1, '', [
        ['id' => 1, 'api_tenant_public_id' => ''],
    ]);
    $handler->afterListRows($ctx);
    assert_same(0, $transport->calls);
});
```

Note: when `$transport->fail = true`, client retries then throws `LebytekApiException` — handler must catch `\Throwable`.

- [ ] **Step 2: Run — expect FAIL**

```bash
php tests/run.php MktLeadsCrudHookHandler
```

- [ ] **Step 3: Implement handler + registry**

```php
<?php

declare(strict_types=1);

namespace App\Application\Marketing;

use App\Infrastructure\Integrations\LebytekApi\LebytekApiClient;
use Lebytek\Framework\Application\Crud\Context\CrudListRowsContext;
use Lebytek\Framework\Application\Crud\Handlers\AbstractCrudHookHandler;
use Lebytek\Framework\Kernel\EnvLoader;

final class MktLeadsCrudHookHandler extends AbstractCrudHookHandler
{
    public function __construct(
        private readonly ?LebytekApiClient $api = null,
    ) {}

    public function afterListRows(CrudListRowsContext $ctx): void
    {
        $rows = $ctx->rows();
        $publicIds = [];
        foreach ($rows as $row) {
            $id = $row['api_tenant_public_id'] ?? null;
            if (is_string($id) && $id !== '') {
                $publicIds[] = $id;
            }
        }
        $publicIds = array_values(array_unique($publicIds));
        if ($publicIds === []) {
            return;
        }

        try {
            $items = $this->client()->getTenantsUsage($publicIds);
        } catch (\Throwable) {
            foreach ($rows as $i => $row) {
                $rows[$i]['messages_usage'] = null;
            }
            $ctx->setRows($rows);

            return;
        }

        foreach ($rows as $i => $row) {
            $pid = $row['api_tenant_public_id'] ?? null;
            if (!is_string($pid) || $pid === '' || !isset($items[$pid]) || !is_array($items[$pid])) {
                $rows[$i]['messages_usage'] = null;
                continue;
            }
            $limit = $items[$pid]['messagesLimitThisMonth'] ?? null;
            if ($limit === null) {
                $rows[$i]['messages_usage'] = null;
                continue;
            }
            $rows[$i]['messages_usage'] = [
                'sent' => (int) ($items[$pid]['messagesSentThisMonth'] ?? 0),
                'limit' => (int) $limit,
            ];
        }
        $ctx->setRows($rows);
    }

    private function client(): LebytekApiClient
    {
        // List enrich must fail fast — do NOT reuse provisioning defaults (30s × 3 retries).
        // Registry does `new $class()` with zero args; EnvLoader construction is intentional
        // (cannot inject the container singleton).
        return $this->api ?? new LebytekApiClient(
            baseUrl: rtrim((string) EnvLoader::get('LEBYTEK_API_URL', ''), '/'),
            token: (string) EnvLoader::get('LEBYTEK_API_TOKEN', ''),
            timeoutSeconds: (int) EnvLoader::get('LEBYTEK_API_LIST_TIMEOUT', 5),
            maxRetries: (int) EnvLoader::get('LEBYTEK_API_LIST_RETRY_MAX', 1),
        );
    }
}
```

In `config/crud_handlers.php`:

```php
'mkt_leads' => \App\Application\Marketing\MktLeadsCrudHookHandler::class,
```

- [ ] **Step 4: Run handler tests — expect PASS**

- [ ] **Step 5: Commit (Lebytek_Framework)**

```bash
git add app/Application/Marketing/MktLeadsCrudHookHandler.php config/crud_handlers.php tests/Marketing/MktLeadsCrudHookHandlerTest.php
git commit -m "$(cat <<'EOF'
feat(marketing): enrich mkt_leads list with message usage

EOF
)"
```

---

### Task 10: Enable column + handler on `mkt_leads` CRUD config

**Files:**
- Modify: `config/cruds/mkt_leads.json`

**Interfaces:**
- Consumes: virtual + progress + `mkt_leads` handler from prior tasks

- [ ] **Step 1: Update JSON**

Near the top of `list.columns` (after `id` or before `nombre`), insert:

```json
{
  "name": "messages_usage",
  "label": "Uso mensajes",
  "format": "progress",
  "priority": 1,
  "virtual": true
}
```

Keep `last_activity_at` unchanged (`priority: 5`).

Set:

```json
"hooks": { "handler": "mkt_leads" }
```

- [ ] **Step 2: Manual smoke (local)**

1. Ensure `.env` has valid `LEBYTEK_API_URL` / `LEBYTEK_API_TOKEN` against an api that already has Tasks 1–3 deployed or local.
2. Open `/admin/crud/mkt_leads?estado=demo_enviada`.
3. Confirm “Uso mensajes” appears with high priority; demos with limit show bar; leads without tenant show `—`.

- [ ] **Step 3: Commit (Lebytek_Framework)**

```bash
git add config/cruds/mkt_leads.json
git commit -m "$(cat <<'EOF'
feat(marketing): show message usage progress on mkt_leads list

EOF
)"
```

---

### Task 11: Mirror contract in Framework docs

**Files:**
- Modify: `Lebytek_Framework/docs/integration/waapi-api-contract.md` (same sections as Task 4)

- [ ] **Step 1: Copy the GET/POST usage sections from api contract into Framework mirror** (keep wording aligned).

- [ ] **Step 2: Commit (Lebytek_Framework)**

```bash
git add docs/integration/waapi-api-contract.md
git commit -m "$(cat <<'EOF'
docs: mirror tenant usage endpoints in waapi contract

EOF
)"
```

---

## Self-review (plan vs spec)

| Spec requirement | Task |
|------------------|------|
| New column `messages_usage` priority 1 | Task 10 |
| Keep `last_activity_at` | Task 10 (untouched) |
| GET single usage | Task 2 |
| POST batch usage | Task 3 |
| Same month count as AccountStatusService | Task 1 |
| Unknown IDs omitted | Task 3 (real ULID + idempotencyHeaders) |
| Progress semáforo + over-limit | Task 6 |
| `—` for null/error/no tenant | Tasks 6, 9 |
| `afterListRows` enrich | Tasks 7, 9 |
| Client batch + chunk 100 | Task 8 |
| Contract api + Framework | Tasks 4, 11 |
| XSS-safe HTML | Task 6 |
| No cache / no last_activity write / no Framework→main merge | Global Constraints |
| Virtual column (required by real CRUD engine; implied by “campo virtual”) | Task 5 |
| Fast-fail list enrich (no 30s×3 hang) | Task 9 |
| Virtual SELECT skip + searchable guard | Task 5 |

**Placeholder scan:** none intentional.  
**Type consistency:** progress value shape is always `['sent' => int, 'limit' => int]`; API JSON uses `messagesSentThisMonth` / `messagesLimitThisMonth`; handler maps between them.

**Spec gap fixed in plan:** Framework CRUD currently validates/selects all list column names against DB — Task 5 adds `virtual: true` so `messages_usage` works.

**Audit gap fixed in plan (2026-07-23):** POST tests need `idempotencyHeaders()` + real ULIDs; Task 2/3 split; Task 5 pure-helper tests + DataService SELECT test; Task 7 wiring mandatory; Task 9 short list timeout.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-23-admin-leads-message-usage-progress.md`. Two execution options:

**1. Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?
