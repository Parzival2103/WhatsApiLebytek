# Audit Remediation (Deploy Bottleneck + Open Findings) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unblock the AI→review→test→fix→audit loop by confirming production deploy of `main` @ ≥ `9e62475` (Issue #17), then close the remaining medium findings from the 2026-07-19 daily audit (PATCH commercial bypass, webhook throttle, commercial Form Requests/tests, docs drift).

**Architecture:** Ops-first: Task 1 is **agent-executed** on the VPS via `ssh lebytek-vps` (clave SSH del operador para el agente). El humano diseña, testa producto y valida con el cliente final; el agente programa, audita y opera deploy/smoke. Code tasks align api with the already-published contract (`PATCH` = `name`/`isActive` only; commercial mutations only via `activate-plan` / `cancel-commercial` / `reactivate-commercial`). Framework `LebytekApiClient` already has cancel/reactivate — Task 5 verifies and closes A-006 without reimplementation.

**Tech Stack:** Laravel 13, Pest, Sanctum, Horizon/Redis, `RateLimiter`, existing webhook HMAC/Bearer middleware, CloudPanel VPS (`lebytek-api`)

**Source audit:** [`docs/automation-reports/daily-audit/2026-07-19.md`](../../automation-reports/daily-audit/2026-07-19.md)

**Issues:** [#17](https://github.com/Parzival2103/WhatsApiLebytek/issues/17) (deploy), [#21](https://github.com/Parzival2103/WhatsApiLebytek/issues/21) (PATCH bypass)

## Global Constraints

- **División de trabajo:** agente = código + audit + ops VPS (`ssh lebytek-vps`); humano = diseño, testing de producto y conformidad con el cliente final. Ejecutar Task 1 (deploy) cuando el usuario pida ejecutar este plan / SDD — no en Cursor Automations no supervisadas.
- **Ops permitidos en Task 1 / Task 5:** `git pull`, `composer install --no-dev`, `npm ci`/`build`, `php artisan migrate --force`, caches, `scribe:generate`, `supervisorctl restart lebytek-api-horizon:*`, verificar/instalar cron `schedule:run`, smokes HTTP, cerrar Issue #17 con evidencia.
- **Ops prohibidos siempre:** editar `.env` de producción o secretos; `git push --force`; borrar BD/datos; merge Framework → `main`; desactivar RBAC / firmas webhook / Horizon.
- **No merge** Framework `feature/backoffice-api-integration` → `main`.
- **No re-issue** of waapi platform token for these changes unless smoke proves the token lacks `tenants.gestionar`.
- **Commercial mutations** only via dedicated endpoints: `POST …/activate-plan`, `POST …/cancel-commercial`, `POST …/reactivate-commercial`. `PATCH /tenants/{id}` must reject commercial fields with **422**.
- **TDD** for Tasks 2–4: failing test before production code.
- **Commits** only when the user asks (suggested messages included for execution sessions).
- **Out of scope this plan:** A-007 `CampaignBatchJob` stub (backlog vertical).

---

## File map

| File | Action | Responsibility |
|------|--------|----------------|
| Production VPS `api.lebytek.com` | Agent via `ssh lebytek-vps` | Pull `main`, migrate, Horizon restart, cron `schedule:run`, smoke |
| `docs/integration/VPS_CHECKLIST.md` | Modify | Record deploy evidence date + HEAD SHA; close #17 criteria |
| `tests/Feature/Api/TenantProvisioningTest.php` | Modify | Assert PATCH rejects commercial fields |
| `app/Http/Requests/Api/V1/UpdateTenantRequest.php` | Modify | Allow only `name`/`isActive`; `prohibited` on commercial keys |
| `app/Services/TenantProvisioningService.php` | Modify | Drop commercial attribute mapping from `update()` |
| `docs/integration/waapi-api-contract.md` | Verify | PATCH section already documents only `name`/`isActive` — no body change unless drift found |
| `app/Providers/AppServiceProvider.php` | Modify | Register `RateLimiter::for('webhooks', …)` 120/min by IP |
| `routes/api.php` | Modify | Add `throttle:webhooks` to webhook group |
| `tests/Feature/Webhooks/WebhookVerificationTest.php` | Modify | Assert 429 after limit |
| `app/Http/Requests/Api/V1/CancelCommercialRequest.php` | Create | Optional `reason` |
| `app/Http/Requests/Api/V1/ReactivateCommercialRequest.php` | Create | Optional `tokenName` |
| `app/Http/Controllers/Api/V1/TenantController.php` | Modify | Type-hint new Form Requests |
| `tests/Feature/Api/TenantCommercialLifecycleTest.php` | Modify | Idempotency + reactivate-already-active + non-platform 403 |
| `CLAUDE.md`, `README.md` | Modify | Laravel 13 wording (A-009) |
| Framework `LebytekApiClient.php` | Verify only | Confirm `cancelCommercial` / `reactivateCommercial` present (A-006) |

---

### Task 1: Deploy VPS api.lebytek.com (A-001 / Issue #17) — AGENT VIA SSH

**Files:**
- Agent ops: VPS `/home/lebytek-api/htdocs/api.lebytek.com` via `ssh lebytek-vps`
- Modify (after evidence): `docs/integration/VPS_CHECKLIST.md` (§ api.lebytek.com)
- Close: GitHub Issue #17 with evidence comment

**Interfaces:**
- Consumes: `main` ≥ `9e62475` (includes `meta` migration, activate-plan, cancel/reactivate, webhook persist + Horizon `supervisor-webhooks`, scheduled `webhooks:check-unprocessed`)
- Produces: Production running that SHA; Horizon processing queue `webhooks`; cron invoking `schedule:run`; smoke green → Issue #17 closable

> Acceso: alias SSH `lebytek-vps` (clave del operador). Preferir comandos no interactivos: `ssh lebytek-vps '…'` en lugar de shell interactiva. No tocar `.env`. Cursor Automations diarias **siguen** sin deploy; este Task corre solo en sesión de ejecución de plan autorizada.

- [ ] **Step 1: Preflight from laptop (read-only)**

```bash
gh api repos/Parzival2103/WhatsApiLebytek/commits/main --jq '.sha[:7]'
# Expected: 9e62475 or newer
```

- [ ] **Step 2: Confirm current prod HEAD via SSH**

```bash
ssh lebytek-vps "sudo -u lebytek-api bash -lc 'cd /home/lebytek-api/htdocs/api.lebytek.com && git fetch origin && git rev-parse --short HEAD && git status -sb && git rev-parse --short origin/main'"
```

Expected: branch `main`, note if behind `origin/main`.

- [ ] **Step 3: Routine deploy (from `docs/DEPLOY.md`) — single SSH session**

```bash
ssh lebytek-vps "sudo -u lebytek-api bash -lc '
set -euo pipefail
cd /home/lebytek-api/htdocs/api.lebytek.com
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan scribe:generate
'"
ssh lebytek-vps "sudo supervisorctl restart lebytek-api-horizon:*"
```

Expected: migrate applies `meta` if missing (idempotent if already applied); Horizon restarts without error.

- [ ] **Step 4: Ensure Laravel scheduler cron (post-#29 watcher)**

```bash
ssh lebytek-vps "crontab -l -u lebytek-api"
```

If missing `schedule:run`, install non-interactively (append if absent):

```bash
ssh lebytek-vps 'CRON_LINE="* * * * * cd /home/lebytek-api/htdocs/api.lebytek.com && php artisan schedule:run >> /dev/null 2>&1"; (crontab -l -u lebytek-api 2>/dev/null | grep -Fqx "$CRON_LINE") || (crontab -l -u lebytek-api 2>/dev/null; echo "$CRON_LINE") | crontab -u lebytek-api -'
```

Verify scheduled commands:

```bash
ssh lebytek-vps "sudo -u lebytek-api bash -lc 'cd /home/lebytek-api/htdocs/api.lebytek.com && php artisan schedule:list'"
```

Expected: line containing `webhooks:check-unprocessed` every five minutes.

- [ ] **Step 5: Confirm Horizon supervises `webhooks`**

```bash
ssh lebytek-vps "sudo -u lebytek-api bash -lc 'cd /home/lebytek-api/htdocs/api.lebytek.com && php artisan horizon:status && php artisan tinker --execute=\"echo implode(\\\",\\\", array_keys(config(\\\"horizon.defaults\\\") ?? []));\"'"
```

Also confirm in Horizon UI if credentials available: `supervisor-webhooks` / queue `webhooks`.

Expected: Horizon running; config includes webhooks supervisor.

- [ ] **Step 6: Smoke health (+ commercial if token available)**

```bash
curl -sf https://api.lebytek.com/up
ssh lebytek-vps "sudo -u lebytek-api bash -lc 'cd /home/lebytek-api/htdocs/api.lebytek.com && git rev-parse --short HEAD'"
```

If platform token is available in the agent session env (never commit it), smoke activate-plan on a disposable demo tenant; otherwise leave commercial E2E to human product testing and still close #17 on `/up` + HEAD + Horizon + cron evidence.

Expected: `/up` 200; VPS HEAD ≥ `9e62475`.

- [ ] **Step 7: Record evidence and close Issue #17**

Agent updates `docs/integration/VPS_CHECKLIST.md` checkboxes and closes the issue:

```bash
SHA=$(ssh lebytek-vps "sudo -u lebytek-api bash -lc 'cd /home/lebytek-api/htdocs/api.lebytek.com && git rev-parse --short HEAD'")
gh issue close 17 --repo Parzival2103/WhatsApiLebytek --comment "Deploy confirmado en VPS api.lebytek.com por agente via ssh lebytek-vps.

- HEAD: ${SHA}
- migrate --force: OK
- Horizon restart: OK (supervisor-webhooks / cola webhooks)
- cron schedule:run: presente (webhooks:check-unprocessed)
- smoke: /up OK

Cierra A-001 del audit 2026-07-19. Validación de producto (cliente final) queda al operador."
```

- [ ] **Step 8: Commit checklist doc updates (only if user asks)**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "$(cat <<'EOF'
docs(ops): record api VPS deploy evidence for audit A-001

EOF
)"
```

---

### Task 2: Close PATCH commercial bypass (A-002 / Issue #21)

**Files:**
- Modify: `tests/Feature/Api/TenantProvisioningTest.php`
- Modify: `app/Http/Requests/Api/V1/UpdateTenantRequest.php`
- Modify: `app/Services/TenantProvisioningService.php` (method `update`, ~lines 54–107)
- Test: `tests/Feature/Api/TenantProvisioningTest.php`
- Close: Issue #21 after merge

**Interfaces:**
- Consumes: `UpdateTenantRequest::rules()`, `TenantProvisioningService::update(Tenant $tenant, array $data): Tenant`, route `api.v1.tenants.update`
- Produces: PATCH accepting only `name` / `isActive`; commercial keys → HTTP 422; service no longer maps commercial columns on update

- [ ] **Step 1: Write the failing Feature test**

Append to `tests/Feature/Api/TenantProvisioningTest.php`:

```php
test('platform PATCH rejects commercial fields that bypass activate-plan', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'name' => 'Before Commercial',
        'commercial_status' => 'demo',
        'plan_slug' => 'demo',
        'messages_monthly_limit' => 100,
    ]);

    $this->withToken($token)
        ->patchJson(route('api.v1.tenants.update', $tenant->public_id), [
            'planSlug' => 'empresa',
            'commercialStatus' => 'active',
            'messagesMonthlyLimit' => 999999,
        ], idempotencyHeaders())
        ->assertUnprocessable();

    $tenant->refresh();
    expect($tenant->plan_slug)->toBe('demo')
        ->and($tenant->commercial_status)->toBe('demo')
        ->and($tenant->messages_monthly_limit)->toBe(100);
});

test('platform PATCH still updates name and isActive', function () {
    $token = platformServiceToken();
    $tenant = Tenant::factory()->create(['name' => 'Rename Me', 'is_active' => true]);

    $this->withToken($token)
        ->patchJson(route('api.v1.tenants.update', $tenant->public_id), [
            'name' => 'Renamed',
            'isActive' => false,
        ], idempotencyHeaders())
        ->assertOk()
        ->assertJsonPath('name', 'Renamed')
        ->assertJsonPath('isActive', false);
});
```

- [ ] **Step 2: Run tests to verify failure**

Run: `php artisan test --filter="platform PATCH rejects commercial"`

Expected: FAIL — response is 200 (or fields applied), not 422.

- [ ] **Step 3: Restrict `UpdateTenantRequest`**

Replace `rules()` in `app/Http/Requests/Api/V1/UpdateTenantRequest.php` with:

```php
public function rules(): array
{
    return [
        'name' => ['sometimes', 'string', 'max:255'],
        'isActive' => ['sometimes', 'boolean'],
        'commercialStatus' => ['prohibited'],
        'planSlug' => ['prohibited'],
        'planName' => ['prohibited'],
        'demoStartedAt' => ['prohibited'],
        'demoExpiresAt' => ['prohibited'],
        'messagesMonthlyLimit' => ['prohibited'],
    ];
}
```

- [ ] **Step 4: Strip commercial mapping from `TenantProvisioningService::update`**

Replace the method body so only `name` / `isActive` are applied (update PHPDoc accordingly):

```php
/**
 * @param  array{
 *     name?: string,
 *     isActive?: bool|null,
 * }  $data
 */
public function update(Tenant $tenant, array $data): Tenant
{
    $attributes = [];

    if (array_key_exists('name', $data) && is_string($data['name'])) {
        $attributes['name'] = $data['name'];
    }

    if (array_key_exists('isActive', $data)) {
        $attributes['is_active'] = (bool) $data['isActive'];
    }

    if ($attributes !== []) {
        $tenant->update($attributes);
    }

    return $tenant->fresh();
}
```

- [ ] **Step 5: Run tests to verify pass**

Run:

```bash
php artisan test --filter="platform PATCH|platform service can list and update"
```

Expected: PASS.

- [ ] **Step 6: Commit (only if user asks)**

```bash
git add tests/Feature/Api/TenantProvisioningTest.php app/Http/Requests/Api/V1/UpdateTenantRequest.php app/Services/TenantProvisioningService.php
git commit -m "$(cat <<'EOF'
fix(api): reject commercial fields on PATCH /tenants

Force plan/status changes through activate-plan and dedicated commercial endpoints.
EOF
)"
```

After merge to `main`: `gh issue close 21 --comment "Closed by <PR>: PATCH rejects commercial fields (A-002)."`

---

### Task 3: Throttle incoming webhooks (A-003)

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (`boot` RateLimiter block)
- Modify: `routes/api.php` (webhook group ~line 95)
- Modify: `tests/Feature/Webhooks/WebhookVerificationTest.php`
- Test: same Feature file

**Interfaces:**
- Consumes: existing `webhook.signature` middleware; `Illuminate\Support\Facades\RateLimiter`
- Produces: named limiter `webhooks` = 120/min by IP; route middleware `['webhook.signature', 'throttle:webhooks']`

- [ ] **Step 1: Write the failing Feature test**

Append to `tests/Feature/Webhooks/WebhookVerificationTest.php` (reuse the HMAC helper pattern already in that file):

```php
test('incoming webhook is rate limited per IP', function () {
    config(['services.webhook.secret' => 'test-webhook-secret']);

    $payload = ['typeWebhook' => 'incomingMessageReceived', 'idMessage' => 'rate-limit-1'];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $body, 'test-webhook-secret');

    // Drive limiter below production 120 for a deterministic test
    RateLimiter::for('webhooks', fn () => Limit::perMinute(2)->by('test-webhook-ip'));

    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        'HTTP_X_EVENT_ID' => 'evt-rate-'.uniqid(),
    ];

    $this->call('POST', route('api.v1.webhooks.incoming'), [], [], [], $headers, $body)
        ->assertOk();

    $headers['HTTP_X_EVENT_ID'] = 'evt-rate-'.uniqid();
    $this->call('POST', route('api.v1.webhooks.incoming'), [], [], [], $headers, $body)
        ->assertOk();

    $headers['HTTP_X_EVENT_ID'] = 'evt-rate-'.uniqid();
    $this->call('POST', route('api.v1.webhooks.incoming'), [], [], [], $headers, $body)
        ->assertStatus(429);
});
```

Add imports at top of the test file if missing:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
```

> Note: If the existing file builds HMAC differently, copy its exact helper instead of inventing a new signature scheme — keep body/secret/header names identical to neighboring tests.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="incoming webhook is rate limited"`

Expected: FAIL — third request is 200 (or route lacks throttle / limiter missing).

- [ ] **Step 3: Register limiter and wire route**

In `app/Providers/AppServiceProvider.php`, inside `boot()` after the existing `messages-send` limiter:

```php
RateLimiter::for('webhooks', function (Request $request) {
    return Limit::perMinute(120)->by($request->ip());
});
```

In `routes/api.php`, change:

```php
Route::prefix('v1/webhooks')->middleware(['webhook.signature'])->group(function (): void {
```

to:

```php
Route::prefix('v1/webhooks')->middleware(['webhook.signature', 'throttle:webhooks'])->group(function (): void {
```

- [ ] **Step 4: Adjust the Feature test to not override the named limiter incorrectly**

If Step 1's inline `RateLimiter::for('webhooks', …)` races with AppServiceProvider, prefer this deterministic approach instead (replace the test body):

```php
test('incoming webhook is rate limited per IP', function () {
    config(['services.webhook.secret' => 'test-webhook-secret']);

    $payload = ['typeWebhook' => 'incomingMessageReceived', 'idMessage' => 'rate-limit-1'];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $body, 'test-webhook-secret');

    $hit = function (string $eventId) use ($body, $signature) {
        return $this->call(
            'POST',
            route('api.v1.webhooks.incoming'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
                'HTTP_X_EVENT_ID' => $eventId,
                'REMOTE_ADDR' => '203.0.113.50',
            ],
            $body,
        );
    };

    // Exhaust the named limiter key used by throttle:webhooks
    $key = 'webhooks:'.sha1('203.0.113.50');
    // Prefer exercising via HTTP: temporarily bind a low limit for this test only
    RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(2)->by($request->ip()));

    $hit('evt-rl-1')->assertSuccessful();
    $hit('evt-rl-2')->assertSuccessful();
    $hit('evt-rl-3')->assertStatus(429);
});
```

Also add `use Illuminate\Http\Request;` if needed.

- [ ] **Step 5: Run webhook Feature suite**

Run:

```bash
php artisan test tests/Feature/Webhooks
```

Expected: PASS (including rate-limit test).

- [ ] **Step 6: Commit (only if user asks)**

```bash
git add app/Providers/AppServiceProvider.php routes/api.php tests/Feature/Webhooks/WebhookVerificationTest.php
git commit -m "$(cat <<'EOF'
feat(api): throttle incoming webhooks at 120/min per IP

EOF
)"
```

---

### Task 4: Commercial Form Requests + lifecycle test gaps (A-004 + A-005)

**Files:**
- Create: `app/Http/Requests/Api/V1/CancelCommercialRequest.php`
- Create: `app/Http/Requests/Api/V1/ReactivateCommercialRequest.php`
- Modify: `app/Http/Controllers/Api/V1/TenantController.php` (`cancelCommercial`, `reactivateCommercial`)
- Modify: `tests/Feature/Api/TenantCommercialLifecycleTest.php`
- Test: same Feature file

**Interfaces:**
- Consumes: `CancelCommercialService::cancel(Tenant $tenant, ?string $reason)`, `ReactivateCommercialService::reactivate(Tenant $tenant, array $payload)`
- Produces: Form Requests with optional `reason` / `tokenName`; Feature coverage for idempotent cancel, reactivate-when-active, non-platform 403

- [ ] **Step 1: Write failing Feature tests**

Append to `tests/Feature/Api/TenantCommercialLifecycleTest.php`:

```php
test('cancel-commercial is idempotent when already cancelled', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'slug' => 'feat-cancel-idempotent',
        'commercial_status' => 'cancelled',
        'plan_slug' => 'starter',
        'meta' => [
            'cancelled_at' => now()->subHour()->toIso8601String(),
            'cancel_reason' => 'already',
        ],
    ]);

    $response = $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.cancel-commercial', $tenant->public_id), [
            'reason' => 'retry',
        ], idempotencyHeaders());

    $response->assertOk()
        ->assertJsonPath('commercialStatus', 'cancelled')
        ->assertJsonPath('tokensRevoked', 0);
});

test('reactivate-commercial on already active tenant returns 200 and null token', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'slug' => 'feat-reactivate-already-active',
        'commercial_status' => 'active',
        'plan_slug' => 'starter',
    ]);

    $response = $this->withToken($platformToken)
        ->postJson(route('api.v1.tenants.reactivate-commercial', $tenant->public_id), [
            'tokenName' => 'should-not-issue',
        ], idempotencyHeaders());

    $response->assertOk()
        ->assertJsonPath('commercialStatus', 'active')
        ->assertJsonPath('token', null);
});

test('non-platform token cannot cancel or reactivate commercial', function () {
    $tenant = Tenant::factory()->create([
        'slug' => 'feat-commercial-forbidden',
        'commercial_status' => 'active',
        'plan_slug' => 'starter',
    ]);
    $clientToken = app(TenantTokenService::class)->issue($tenant, 'cliente')->plainTextToken;

    $this->withToken($clientToken)
        ->postJson(route('api.v1.tenants.cancel-commercial', $tenant->public_id), [], idempotencyHeaders())
        ->assertForbidden();

    $this->withToken($clientToken)
        ->postJson(route('api.v1.tenants.reactivate-commercial', $tenant->public_id), [], idempotencyHeaders())
        ->assertForbidden();
});
```

- [ ] **Step 2: Run new tests (may already pass on service behavior)**

Run: `php artisan test tests/Feature/Api/TenantCommercialLifecycleTest.php`

Expected: new assertions either FAIL (if service differs) or PASS (then continue to Form Request wiring; do not skip Form Requests).

- [ ] **Step 3: Create Form Requests**

`app/Http/Requests/Api/V1/CancelCommercialRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CancelCommercialRequest extends FormRequest
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
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
```

`app/Http/Requests/Api/V1/ReactivateCommercialRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ReactivateCommercialRequest extends FormRequest
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
        return [
            'tokenName' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
```

- [ ] **Step 4: Wire controller**

In `app/Http/Controllers/Api/V1/TenantController.php`:

1. Add imports for `CancelCommercialRequest` and `ReactivateCommercialRequest`.
2. Change signatures:

```php
public function cancelCommercial(CancelCommercialRequest $request, Tenant $tenant): JsonResponse
{
    $this->ensurePlatformService($request);

    $reason = $request->validated('reason');
    $result = $this->cancelCommercialService->cancel(
        $tenant,
        is_string($reason) && $reason !== '' ? $reason : null,
    );

    return response()->json([
        'tenant' => (new TenantResource($result['tenant']))->resolve(),
        'commercialStatus' => 'cancelled',
        'tokensRevoked' => $result['tokensRevoked'],
    ], 200);
}

public function reactivateCommercial(ReactivateCommercialRequest $request, Tenant $tenant): JsonResponse
{
    $this->ensurePlatformService($request);

    $tokenName = $request->validated('tokenName');
    $result = $this->reactivateCommercialService->reactivate($tenant, [
        'tokenName' => is_string($tokenName) && $tokenName !== '' ? $tokenName : 'membresia-reactivated',
    ]);

    return response()->json([
        'tenant' => (new TenantResource($result['tenant']))->resolve(),
        'commercialStatus' => 'active',
        'token' => $result['token'],
    ], $result['created'] ? 201 : 200);
}
```

- [ ] **Step 5: Re-run commercial lifecycle suite**

Run: `php artisan test tests/Feature/Api/TenantCommercialLifecycleTest.php`

Expected: PASS (all tests in file).

- [ ] **Step 6: Commit (only if user asks)**

```bash
git add app/Http/Requests/Api/V1/CancelCommercialRequest.php app/Http/Requests/Api/V1/ReactivateCommercialRequest.php app/Http/Controllers/Api/V1/TenantController.php tests/Feature/Api/TenantCommercialLifecycleTest.php
git commit -m "$(cat <<'EOF'
refactor(api): Form Requests and tests for commercial lifecycle

EOF
)"
```

---

### Task 5: Verify Framework client drift (A-006) — no reimplementation

**Files:**
- Verify: `Lebytek_Framework/app/Infrastructure/Integrations/LebytekApi/LebytekApiClient.php` (methods already present)
- Optional human: Framework VPS pull of `feature/backoffice-api-integration` (not merge to `main`)
- Modify (api audit note only if needed): next daily-audit report can mark A-006 resolved

**Interfaces:**
- Consumes: `LebytekApiClient::cancelCommercial(string $tenantPublicId, ?string $idempotencyKey = null, ?string $reason = null): array`
- Consumes: `LebytekApiClient::reactivateCommercial(string $tenantPublicId, array $payload = [], ?string $idempotencyKey = null): array`
- Produces: Confirmation that audit A-006 is **already implemented** on Framework feature branch; ops may still need VPS sync

- [ ] **Step 1: Confirm methods exist on feature branch**

From `Lebytek_Framework` worktree:

```bash
git branch --show-current
# Expected: feature/backoffice-api-integration

rg -n "function cancelCommercial|function reactivateCommercial|function activatePlan" app/Infrastructure/Integrations/LebytekApi/LebytekApiClient.php
```

Expected: all three methods present (local evidence as of 2026-07-19: commit `aa9954a` lineage).

- [ ] **Step 2: Run Framework marketing tests that exercise the client**

```bash
php tests/run.php Marketing
```

Expected: PASS (or document any pre-existing failures unrelated to cancel/reactivate).

- [ ] **Step 3: Agent — deploy Framework feature branch if VPS is behind (same session if needed)**

Do **not** merge to Framework `main`. If lebytek.com / waapi runtime lacks cancel/reactivate, pull `feature/backoffice-api-integration` via `ssh lebytek-vps` using the Framework deploy runbook (same alias; distinct site path under CloudPanel). Only after Task 1 api deploy is green.

- [ ] **Step 4: No api code commit for A-006**

Mark A-006 resolved in the next AUTOMATION-01 report (or a short note in Issue #17 close comment if Framework VPS was part of that deploy window).

---

### Task 6: Docs — Laravel 13 wording (A-009)

**Files:**
- Modify: `CLAUDE.md` (table row for Esta API)
- Modify: `README.md` (stack bullet)
- Leave historical specs/plans that say "Laravel 11+" untouched unless editing them for another reason

**Interfaces:**
- Produces: Operator-facing docs match `composer.json` (`laravel/framework: ^13.8`) and `AGENTS.md`

- [ ] **Step 1: Patch CLAUDE.md**

Change:

```markdown
| **Esta API** | `Parzival2103/WhatsApiLebytek` | `api.lebytek.com` | Laravel 11+, Inertia+Vue, Horizon, Sanctum |
```

to:

```markdown
| **Esta API** | `Parzival2103/WhatsApiLebytek` | `api.lebytek.com` | Laravel 13, Inertia+Vue, Horizon, Sanctum |
```

- [ ] **Step 2: Patch README.md**

Change the stack line that says `Laravel 11+` to `Laravel 13`.

- [ ] **Step 3: Commit (only if user asks)**

```bash
git add CLAUDE.md README.md
git commit -m "$(cat <<'EOF'
docs: align stack wording with Laravel 13 runtime

EOF
)"
```

---

## Verification (whole plan)

After Tasks 2–4 + 6 (local):

```bash
composer test
```

Expected: green (same or higher count than post-#29 baseline ~193 passed).

After Task 1 (prod): Issue #17 closed with SHA + Horizon + cron evidence.

---

## Self-review (spec / audit coverage)

| Audit ID | Sev | Task | Notes |
|----------|-----|------|-------|
| A-001 | Alto | Task 1 | Deploy bottleneck — agent via `ssh lebytek-vps` |
| A-002 | Medio | Task 2 | Closes #21 |
| A-003 | Medio | Task 3 | throttle:webhooks 120/min |
| A-004 | Medio | Task 4 | Form Requests |
| A-005 | Medio | Task 4 | Lifecycle tests |
| A-006 | Medio | Task 5 | Verify-only; code already on Framework feature branch |
| A-007 | Bajo | — | Explicitly out of scope (campaigns stub) |
| A-009 | Bajo | Task 6 | CLAUDE + README only |

**Placeholder scan:** none intentional.

**Type consistency:** `CancelCommercialRequest` / `ReactivateCommercialRequest` names match controller wiring; limiter name `webhooks` matches `throttle:webhooks`.

---

## Execution order (recommended)

1. **Task 1 first** (unblocks prod validation of everything already on `main`).
2. Tasks 2 → 3 → 4 in api (independent PRs or one PR).
3. Task 5 verify Framework (parallel with 2–4).
4. Task 6 docs (can fold into the same api PR as Task 2).
