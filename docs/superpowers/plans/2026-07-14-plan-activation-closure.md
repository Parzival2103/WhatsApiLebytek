# Plan Activation Closure (Test Gaps + Ops) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the post-ship audit gaps for plan activation: Feature coverage for semantic 200 replay, assert plan-aware job Redis `maxAttempts`, mark the historical plan as done, and hand off VPS migrate + smoke (human-only).

**Architecture:** Product code already lives on `main` (PR #14). This plan only adds tests against existing `ActivatePlanService` / `TransactionalMessageJob` + `PlanRateResolver`, updates tracking docs, and documents an ops checklist that agents must not execute without explicit human approval.

**Tech Stack:** Laravel 13, Pest, Sanctum, Redis throttle middleware (`RateLimitedWithRedis`)

**Spec:** [`docs/superpowers/specs/2026-07-14-plan-activation-and-package-limits-design.md`](../specs/2026-07-14-plan-activation-and-package-limits-design.md)

**Historical ship plan (do not re-run Tasks 1–7):** [`docs/superpowers/plans/2026-07-14-plan-activation-and-package-limits.md`](./2026-07-14-plan-activation-and-package-limits.md)

**Companion (Framework hardening + flag):** Lebytek_Framework `docs/superpowers/plans/2026-07-14-manual-membership-purchase.md`

**Prod sequence (identical to both ship plans):** this closure = sequence step 2; Framework hardening = step 3; Framework VPS + flag = steps 4–5. Do **not** enable `MKT_MEMBERSHIP_AUTHORIZE_ENABLED` from this plan.

## Global Constraints

- **Code of activate-plan / catalog / rates is already on `main`.** Do not reimplement `ActivatePlanService`, `config/plans.php`, routes, or contract docs unless a test proves a bug.
- **Two idempotency layers:** (1) HTTP `Idempotency-Key` middleware caches the first response (often 201 + token); (2) semantic replay is same `planSlug` + `orderExternalRef` with a **different** `Idempotency-Key` → HTTP **200** and `token: null`.
- **`demo` unlock:** Framework never sends `planSlug=demo`. Do not spend this closure plan tightening FormRequest unless a regression test forces it — unlock refusal is Framework hardening Task 2.
- **No deploy / SSH / VPS / `migrate --force` / production `.env`** unless the user explicitly orders it in chat. Task 4 is a human checklist only.
- **No merge Framework → main** and no enabling `MKT_MEMBERSHIP_AUTHORIZE_ENABLED` from this repo’s agent work.
- **TDD:** failing test before any production code change (Tasks 1–2 should pass without code changes if implementation is correct; if they fail, fix the minimal bug then re-run).
- **Commits:** only when the user asks (suggested messages below for execution sessions).

---

## File map

| File | Action | Responsibility |
|------|--------|----------------|
| `tests/Feature/Api/ActivatePlanTest.php` | Modify | Feature: semantic 200 with new Idempotency-Key |
| `tests/Unit/Queue/HorizonQueueConfigTest.php` | Modify | Assert `RateLimitedWithRedis` `maxAttempts` from plan catalog |
| `docs/superpowers/plans/2026-07-14-plan-activation-and-package-limits.md` | Modify | Mark Tasks 1–7 Done; point to this closure plan |
| `docs/superpowers/specs/2026-07-14-plan-activation-and-package-limits-design.md` | Modify | Flip optional test gaps to Done after Tasks 1–2 |
| Production VPS (`api.lebytek.com`) | Human only | `meta` migration + smoke activate-plan |

**No new app classes expected.** Touches only tests + tracking markdown unless a regression forces a tiny production fix.

---

### Task 1: Feature test — semantic 200 replay

**Files:**
- Modify: `tests/Feature/Api/ActivatePlanTest.php`
- Test: same file (new test at end)

**Interfaces:**
- Consumes: `POST` route `api.v1.tenants.activate-plan`; `ActivatePlanService` returns `created: false` → controller HTTP 200 + `token: null`
- Produces: Feature coverage that Framework authorize retries (new Idempotency-Key, same order ref) do not rotate tokens again

- [ ] **Step 1: Write the failing Feature test**

Append to `tests/Feature/Api/ActivatePlanTest.php`:

```php
test('semantic replay with different idempotency key returns 200 and null token', function () {
    $platformToken = platformServiceToken();
    $tenant = Tenant::factory()->create([
        'slug' => 'feat-activate-semantic',
        'commercial_status' => 'demo',
        'plan_slug' => 'demo',
        'messages_monthly_limit' => 100,
    ]);

    $payload = [
        'planSlug' => 'starter',
        'billingCycle' => 'monthly',
        'orderExternalRef' => '01JXORDERSEMANTIC1',
        'tokenName' => 'cliente-paid-starter',
    ];

    $first = $this->withToken($platformToken)
        ->postJson(
            route('api.v1.tenants.activate-plan', $tenant->public_id),
            $payload,
            ['Idempotency-Key' => 'activate-semantic-first-'.uniqid()],
        );

    $first->assertCreated()
        ->assertJsonPath('plan.slug', 'starter')
        ->assertJsonPath('token', fn ($token) => is_string($token) && $token !== '');

    $issuedToken = $first->json('token');

    $second = $this->withToken($platformToken)
        ->postJson(
            route('api.v1.tenants.activate-plan', $tenant->public_id),
            $payload,
            ['Idempotency-Key' => 'activate-semantic-second-'.uniqid()],
        );

    $second->assertOk()
        ->assertJsonPath('plan.slug', 'starter')
        ->assertJsonPath('plan.messagesMonthlyLimit', 5000)
        ->assertJsonPath('tenant.commercialStatus', 'active')
        ->assertJsonPath('token', null);

    expect(\Laravel\Sanctum\PersonalAccessToken::findToken($issuedToken))->not->toBeNull();
});
```

Notes:
- Do **not** reuse `idempotencyHeaders()` for both calls — that helper shares one key and hits middleware cache (201 replay), which already exists as `idempotency key replay returns same body`.
- `assertJsonPath('token', null)` asserts JSON `null`, not missing key.

- [ ] **Step 2: Run test to verify it fails (or unexpected fail)**

Run:

```bash
php artisan test --filter="semantic replay with different idempotency key"
```

Expected if endpoint wiring is broken: FAIL (404/403/wrong status).  
Expected if ship is healthy: PASS on first run (TDD “write test first” still applies — you wrote the assertion before claiming coverage). If it PASSes immediately, that is success for this gap; continue.  
If it FAILs with 201 on second call or non-null token → bug in `ActivatePlanService` semantic branch or controller status mapping — fix only that next step.

- [ ] **Step 3: Fix only if failing**

If second response is not 200 / `token` not null, open `app/Services/ActivatePlanService.php` and confirm the early return when:

- `commercial_status === 'active'`
- `plan_slug === $slug`
- `meta['activated_order_ref'] === $orderRef`

and `app/Http/Controllers/Api/V1/TenantController.php` `activatePlan` uses `$result['created'] ? 201 : 200`. Do not change catalog or revoke logic unless the test proves they re-fire.

Minimal correct early return (already present on main — restore only if missing):

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --filter="semantic replay with different idempotency key"
```

Expected: PASS

Also run sibling Feature file:

```bash
php artisan test tests/Feature/Api/ActivatePlanTest.php
```

Expected: all PASS (including existing Idempotency-Key 201 replay).

- [ ] **Step 5: Commit (suggested; only if user approved commits)**

```bash
git add tests/Feature/Api/ActivatePlanTest.php
git commit -m "$(cat <<'EOF'
test(api): cover activate-plan semantic 200 replay

EOF
)"
```

---

### Task 2: Unit test — job middleware `maxAttempts` by plan

**Files:**
- Modify: `tests/Unit/Queue/HorizonQueueConfigTest.php`
- Consumes: `TransactionalMessageJob::middleware()`, `App\Jobs\Middleware\RateLimitedWithRedis`, `PlanRateResolver::jobSendPerMinute`
- Produces: Direct assert that starter → 60 and demo fallback → 30 (closes “no Feature assert on middleware args” gap)

- [ ] **Step 1: Write the failing / covering tests**

Replace the weak middleware test in `tests/Unit/Queue/HorizonQueueConfigTest.php` (the one that only checks `toHaveCount(1)`) with the following block, and keep the horizon supervisor + queue stub tests unchanged:

```php
<?php

use App\Jobs\CampaignBatchJob;
use App\Jobs\Middleware\RateLimitedWithRedis;
use App\Jobs\TransactionalMessageJob;
use App\Models\Core\Tenant;
use App\Models\Integration\Mensaje;
use App\Support\PlanRateResolver;
use ReflectionProperty;

test('horizon defines isolated supervisors for default transactional and campaigns queues', function () {
    $defaults = config('horizon.defaults');

    expect($defaults)->toHaveKeys(['supervisor-default', 'supervisor-transactional', 'supervisor-campaigns'])
        ->and($defaults['supervisor-default']['queue'])->toBe(['default'])
        ->and($defaults['supervisor-transactional']['queue'])->toBe(['transactional'])
        ->and($defaults['supervisor-campaigns']['queue'])->toBe(['campaigns']);
});

test('stub jobs dispatch to the correct queues', function () {
    $transactional = new TransactionalMessageJob(1);
    $campaign = new CampaignBatchJob('campaign-ulid', ['recipient-a', 'recipient-b']);

    expect($transactional->queue)->toBe('transactional')
        ->and($campaign->queue)->toBe('campaigns');
});

test('transactional job redis throttle uses starter plan job rate', function () {
    $tenant = Tenant::factory()->create([
        'slug' => 'job-rate-starter',
        'plan_slug' => 'starter',
    ]);
    $mensaje = Mensaje::factory()->create(['tenant_id' => $tenant->id]);
    $job = new TransactionalMessageJob($mensaje->id);

    $middleware = $job->middleware();
    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(RateLimitedWithRedis::class);

    $maxAttempts = (new ReflectionProperty(RateLimitedWithRedis::class, 'maxAttempts'))
        ->getValue($middleware[0]);

    expect($maxAttempts)->toBe(60)
        ->and($maxAttempts)->toBe(PlanRateResolver::jobSendPerMinute($tenant));
});

test('transactional job redis throttle falls back to demo rate when plan slug missing', function () {
    $tenant = Tenant::factory()->create([
        'slug' => 'job-rate-demo-fallback',
        'plan_slug' => null,
    ]);
    $mensaje = Mensaje::factory()->create(['tenant_id' => $tenant->id]);
    $job = new TransactionalMessageJob($mensaje->id);

    $middleware = $job->middleware()[0];
    $maxAttempts = (new ReflectionProperty(RateLimitedWithRedis::class, 'maxAttempts'))
        ->getValue($middleware);

    expect($maxAttempts)->toBe(30)
        ->and($maxAttempts)->toBe(PlanRateResolver::jobSendPerMinute($tenant));
});
```

Why Reflection: `RateLimitedWithRedis::$maxAttempts` is a private constructor-promoted property; do not widen visibility just for the test.

- [ ] **Step 2: Run tests**

```bash
php artisan test tests/Unit/Queue/HorizonQueueConfigTest.php
```

Expected: PASS.  
If FAIL because `maxAttempts` is still hard-coded (e.g. 60 for every tenant): open `app/Jobs/TransactionalMessageJob.php` `middleware()` and restore:

```php
public function middleware(): array
{
    $mensaje = Mensaje::query()->withoutGlobalScope('tenant')->find($this->mensajeId);
    $tenantKey = $mensaje?->tenant_id ?? 'unknown';
    $tenant = $mensaje?->tenant_id
        ? Tenant::query()->find($mensaje->tenant_id)
        : null;
    $maxAttempts = PlanRateResolver::jobSendPerMinute($tenant);

    return [
        new RateLimitedWithRedis("green-api:tenant:{$tenantKey}", maxAttempts: $maxAttempts, decaySeconds: 60),
    ];
}
```

- [ ] **Step 3: Re-run focused + related resolver unit**

```bash
php artisan test tests/Unit/Queue/HorizonQueueConfigTest.php tests/Unit/Support/PlanRateResolverTest.php
```

Expected: all PASS.

- [ ] **Step 4: Commit (suggested; only if user approved commits)**

```bash
git add tests/Unit/Queue/HorizonQueueConfigTest.php
git commit -m "$(cat <<'EOF'
test(queue): assert plan-aware transactional job throttle

EOF
)"
```

---

### Task 3: Tracking docs — mark shipped + flip audit gaps

**Files:**
- Modify: `docs/superpowers/plans/2026-07-14-plan-activation-and-package-limits.md` (header + optional checkbox note)
- Modify: `docs/superpowers/specs/2026-07-14-plan-activation-and-package-limits-design.md` (Partial gaps table)

- [ ] **Step 1: Update historical plan header**

At the top of `docs/superpowers/plans/2026-07-14-plan-activation-and-package-limits.md`, ensure the SHIPPED blurb includes:

```markdown
> **SHIPPED (2026-07-14):** Tasks 1–7 landed on `main` via PR #14 (`30bc4b6`).
> Residual test/ops work: [`2026-07-14-plan-activation-closure.md`](./2026-07-14-plan-activation-closure.md).
> Checkboxes in Tasks 1–7 below are historical; treat them as done.
```

Do not rewrite the 1.2k-line task bodies.

- [ ] **Step 2: Update design spec audit table**

In `docs/superpowers/specs/2026-07-14-plan-activation-and-package-limits-design.md`, under **Partial / optional gaps**, change the two Low rows after Tasks 1–2 land:

| Gap | Severity | Notes |
|-----|----------|--------|
| Feature test for semantic 200 replay | Done | `ActivatePlanTest` — different Idempotency-Key |
| Direct assert of job middleware `maxAttempts` | Done | `HorizonQueueConfigTest` + Reflection |

Leave **Soft-deleted tenant** as Info. Leave **ops migrate/smoke / Framework authorize flag** under **Not done** until Task 4 is finished by a human.

- [ ] **Step 3: Commit (suggested; only if user approved commits)**

```bash
git add docs/superpowers/plans/2026-07-14-plan-activation-and-package-limits.md \
  docs/superpowers/plans/2026-07-14-plan-activation-closure.md \
  docs/superpowers/specs/2026-07-14-plan-activation-and-package-limits-design.md
git commit -m "$(cat <<'EOF'
docs: close plan-activation test gaps tracking

EOF
)"
```

---

### Task 4: Ops handoff — migrate `meta` + smoke (human only)

**Files:** none in git (production VPS)

**Interfaces:**
- Consumes: migration `database/migrations/2026_07_14_160000_add_meta_to_core_tenants_table.php` already on `main`
- Consumes: platform Bearer (`LEBYTEK_API_TOKEN` / platform user token) against `https://api.lebytek.com`
- Produces: confirmation that Authorize in Framework can safely call activate-plan

**Agent rule:** stop after printing this checklist unless the user explicitly says to SSH/deploy.

- [ ] **Step 1: Confirm local migration file exists**

```bash
php artisan migrate:status
```

Expected locally: `2026_07_14_160000_add_meta_to_core_tenants_table` is Ran (or Pending in a fresh DB). Do not run production migrate from the agent.

- [ ] **Step 2: Human VPS deploy checklist (copy to operator)**

On VPS as site user `lebytek-api` (see `docs/DEPLOY.md`):

```bash
cd /home/lebytek-api/htdocs/api.lebytek.com
sudo -u lebytek-api git pull origin main
sudo -u lebytek-api composer install --no-dev --optimize-autoloader
sudo -u lebytek-api php artisan migrate --force
sudo -u lebytek-api php artisan config:cache
sudo -u lebytek-api php artisan route:cache
supervisorctl restart lebytek-api-horizon:*
```

Verify column:

```bash
sudo -u lebytek-api php artisan tinker --execute="echo Schema::hasColumn('core_tenants','meta') ? 'meta:yes' : 'meta:no';"
```

Expected: `meta:yes`

- [ ] **Step 3: Human smoke — activate starter on a demo tenant**

Replace placeholders. Use a disposable demo tenant + save the returned plain token once.

```bash
# 1) Activate (platform token)
curl -sS -X POST "https://api.lebytek.com/api/v1/tenants/TENANT_PUBLIC_ID/activate-plan" \
  -H "Authorization: Bearer PLATFORM_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Idempotency-Key: smoke-activate-$(date +%s)" \
  -d '{
    "planSlug": "starter",
    "billingCycle": "monthly",
    "orderExternalRef": "SMOKE-ORDER-1",
    "tokenName": "cliente-paid-starter-smoke"
  }'
```

Expect: HTTP **201**, `tenant.commercialStatus=active`, `plan.messagesMonthlyLimit=5000`, non-empty `token`.

```bash
# 2) Old demo token must fail
curl -sS -o /dev/null -w "%{http_code}\n" -X POST "https://api.lebytek.com/api/v1/account/status" \
  -H "Authorization: Bearer OLD_DEMO_TOKEN" \
  -H "Accept: application/json"
```

Expect: `401`

```bash
# 3) New token works + quota raised
curl -sS -X POST "https://api.lebytek.com/api/v1/account/status" \
  -H "Authorization: Bearer NEW_TOKEN_FROM_STEP_1" \
  -H "Accept: application/json"
```

Expect: `commercialStatus=active`, plan slug `starter`, monthly limit 5000. Same Green instance ids as before activate (no re-QR).

```bash
# 4) Semantic replay (different Idempotency-Key)
curl -sS -X POST "https://api.lebytek.com/api/v1/tenants/TENANT_PUBLIC_ID/activate-plan" \
  -H "Authorization: Bearer PLATFORM_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Idempotency-Key: smoke-activate-replay-$(date +%s)" \
  -d '{
    "planSlug": "starter",
    "billingCycle": "monthly",
    "orderExternalRef": "SMOKE-ORDER-1",
    "tokenName": "cliente-paid-starter-smoke"
  }'
```

Expect: HTTP **200**, `token: null`.

- [ ] **Step 4: Hand off Framework authorize flag (do not enable from this repo)**

This smoke completes **sequence step 2** only. Enabling `MKT_MEMBERSHIP_AUTHORIZE_ENABLED` is **sequence step 5** in Framework plan Task 8 and still requires:

1. This smoke (201 + semantic 200 + token rotate) — sequence step 2.
2. Framework hardening Tasks 1–7 deployed — especially Task 2 (`token: null` → markPaid, no email #3; refuse `demo` slug) — sequence step 3.
3. Framework VPS membership migrations + `MKT_BANK_*` / alert numbers — sequence step 4.

Do **not** flip the Framework env flag from an api-repo agent session. Point ops to `Lebytek_Framework/docs/superpowers/plans/2026-07-14-manual-membership-purchase.md` Task 8.

- [ ] **Step 5: No git commit** for ops-only work. Optionally note completion in the design spec **Not done** → move items 1–3 to Done with date — human or a later docs commit.

---

### Task 5: Regression suite (local)

**Files:** none new

- [ ] **Step 1: Run focused suites**

```bash
php artisan test \
  tests/Unit/Support/PlanCatalogTest.php \
  tests/Unit/Support/PlanRateResolverTest.php \
  tests/Unit/Services/ActivatePlanServiceTest.php \
  tests/Unit/Queue/HorizonQueueConfigTest.php \
  tests/Feature/Api/ActivatePlanTest.php \
  tests/Feature/Api/PlanAwareMessageSendThrottleTest.php
```

Expected: all PASS.

- [ ] **Step 2: Optional full suite**

```bash
composer test
```

Expected: PASS (ignore unrelated pre-existing failures outside activate-plan — do not expand scope).

- [ ] **Step 3: Skip empty commit** unless Task 3 docs were left dirty.

---

## Self-review (spec coverage)

| Spec / audit item | Task |
|-------------------|------|
| Canonical catalog, activate-plan, rates, contract | Already on `main` — historical plan Tasks 1–7 |
| Feature test semantic 200 replay | Task 1 |
| Direct assert job middleware `maxAttempts` | Task 2 |
| Plan file checkboxes / tracking lag | Task 3 |
| Soft-deleted tenant → SoftDeletes 404 | Out of scope (Info); no service guard required |
| VPS `meta` migration | Task 4 (human) |
| Prod smoke activate + token rotate + quota | Task 4 (human) |
| Framework `MKT_MEMBERSHIP_AUTHORIZE_ENABLED` | Framework Task 8 only (after this smoke + Framework Task 2 + Framework VPS) — **not** enabled from api repo |
| Campaigns / multi-instance hard caps / billing portal | Still out of scope |

**Placeholder scan:** none intentional.  
**Type consistency:** `planSlug` / `billingCycle` / `orderExternalRef` / `token` null on 200 match shipped service + contract.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-14-plan-activation-closure.md`.

**Context for the executor:** Do not re-implement activate-plan. Start at Task 1 (tests). Stop before Task 4 SSH unless the user explicitly requests production ops.

**Two execution options:**

**1. Subagent-Driven (recommended)** — Fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch with checkpoints

**Which approach?**
