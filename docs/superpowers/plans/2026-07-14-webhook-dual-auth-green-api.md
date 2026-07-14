# Webhook Dual Auth (HMAC + Bearer Green API) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow Green API real webhooks (`Authorization: Bearer {WEBHOOK_SECRET}`) on `POST /api/v1/webhooks/incoming` without removing or weakening the existing HMAC + `X-Event-Id` path used by other emitters and tests.

**Architecture:** Extend `VerifyWebhookSignature` in place: if `X-Webhook-Signature` is present, validate HMAC only (invalid → 401, no Bearer fallback). If absent, accept `Authorization: Bearer` matching `services.webhooks.secret`. Extend `WebhookIdempotency` so `X-Event-Id` remains preferred, otherwise derive a stable event key from Green payload fields or `sha1(rawBody)` — never 422 solely because Green omits `X-Event-Id`. Same route group; no new endpoint; no migrations.

**Tech Stack:** Laravel 13, Pest Feature tests, Redis/array cache, existing middleware aliases `webhook.signature` / `webhook.idempotency`

**Spec:** [`docs/superpowers/specs/2026-07-14-webhook-dual-auth-green-api-design.md`](../specs/2026-07-14-webhook-dual-auth-green-api-design.md)

## Global constraints

- **Do not remove HMAC.** Invalid HMAC must not fall through to Bearer.
- **Do not open the endpoint.** Empty secret → 500; no auth → 401.
- **Same secret** for HMAC and Bearer: `config('services.webhooks.secret')` / `WEBHOOK_SECRET`.
- **No Basic Auth** in v1. **No** `int_webhooks` persistence. **No** new webhook types in the controller.
- **No deploy / SSH / VPS** unless the user explicitly orders it.
- **TDD:** failing tests before implementation in each code task.
- **Commits:** only when the user asks to commit (or when executing this plan with explicit commit approval). Commit messages below are suggested.

---

## File map

| File | Action | Responsibility |
|------|--------|----------------|
| `tests/Feature/Webhooks/WebhookVerificationTest.php` | Modify | Keep HMAC cases; add Bearer / dual / missing-auth / HMAC-wins-over-Bearer |
| `tests/Feature/Webhooks/WebhookGreenBearerTest.php` | Create | Bearer + `stateInstanceChanged` updates instancia; idempotency without `X-Event-Id` |
| `app/Http/Middleware/VerifyWebhookSignature.php` | Modify | HMAC primary + Bearer fallback; set `webhook_auth_mode` |
| `app/Http/Middleware/WebhookIdempotency.php` | Modify | Resolve event id from header / payload / body hash |
| `app/Http/Controllers/Api/V1/IncomingWebhookController.php` | Modify | Scribe/docblock only (dual auth wording) |
| `docs/integration/waapi-api-contract.md` | Modify | Dual auth + derived idempotency |
| `.env.example` | Modify | Unify webhook secret docs; deprecate misleading `GREEN_API_WEBHOOK_TOKEN` usage |
| `docs/DEPLOY.md` | Modify | Clarify `WEBHOOK_SECRET` = HMAC + Green Bearer / `webhookUrlToken` |

---

### Task 1: Failing tests — dual auth (HMAC primary + Bearer fallback)

**Files:**
- Modify: `tests/Feature/Webhooks/WebhookVerificationTest.php`

- [ ] **Step 1: Add failing Bearer / dual-auth tests**

Keep the three existing tests unchanged. Append to `tests/Feature/Webhooks/WebhookVerificationTest.php`:

```php
test('webhook accepts bearer token when hmac signature header is absent', function () {
    $payload = ['event' => 'message.received'];
    $body = json_encode($payload);

    $this->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Event-Id' => 'evt-bearer-001',
            'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
        ],
        $body,
    )->assertOk()->assertJson(['received' => true, 'duplicate' => false]);
});

test('webhook rejects invalid bearer token', function () {
    $payload = ['event' => 'message.received'];

    $this->postJson(route('api.v1.webhooks.incoming'), $payload, [
        'X-Event-Id' => 'evt-bearer-002',
        'Authorization' => 'Bearer wrong-secret',
    ])->assertUnauthorized();
});

test('webhook rejects request with neither signature nor bearer', function () {
    $this->postJson(route('api.v1.webhooks.incoming'), ['event' => 'x'], [
        'X-Event-Id' => 'evt-none-001',
    ])->assertUnauthorized();
});

test('invalid hmac does not fall back to bearer on same request', function () {
    $payload = ['event' => 'message.received'];
    $body = json_encode($payload);

    $this->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Event-Id' => 'evt-hmac-wins-001',
            'HTTP_X-Webhook-Signature' => 'definitely-invalid',
            'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
        ],
        $body,
    )->assertUnauthorized();
});

test('webhook returns 500 when secret is not configured', function () {
    config(['services.webhooks.secret' => '']);

    $this->postJson(route('api.v1.webhooks.incoming'), ['event' => 'x'], [
        'X-Event-Id' => 'evt-no-secret',
        'Authorization' => 'Bearer anything',
    ])->assertStatus(500);
});
```

- [ ] **Step 2: Run the new tests — expect FAIL**

Run:

```bash
php artisan test --filter="webhook accepts bearer|webhook rejects invalid bearer|neither signature nor bearer|does not fall back to bearer|secret is not configured"
```

Expected: FAIL — Bearer path still gets `Missing webhook signature` (401) or existing abort messages; new cases do not pass yet. Existing three HMAC tests should still PASS if run alone.

- [ ] **Step 3: Commit tests only (optional / when user asks)**

```bash
git add tests/Feature/Webhooks/WebhookVerificationTest.php
git commit -m "$(cat <<'EOF'
test(webhooks): add failing dual-auth Bearer cases per dual-auth spec

EOF
)"
```

---

### Task 2: Implement `VerifyWebhookSignature` dual auth

**Files:**
- Modify: `app/Http/Middleware/VerifyWebhookSignature.php`

- [ ] **Step 1: Replace middleware handle with HMAC-then-Bearer logic**

Replace the body of `app/Http/Middleware/VerifyWebhookSignature.php` with:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.webhooks.secret');

        if (! is_string($secret) || $secret === '') {
            abort(500, 'Webhook secret is not configured.');
        }

        $signature = $request->header('X-Webhook-Signature', '');

        if (is_string($signature) && $signature !== '') {
            $expected = hash_hmac('sha256', $request->getContent(), $secret);

            if (! hash_equals($expected, $signature)) {
                abort(401, 'Invalid webhook signature.');
            }

            $request->attributes->set('webhook_auth_mode', 'hmac');

            return $next($request);
        }

        $authorization = $request->header('Authorization', '');

        if (is_string($authorization) && preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches) === 1) {
            $token = $matches[1];

            if (hash_equals($secret, $token)) {
                $request->attributes->set('webhook_auth_mode', 'bearer');

                return $next($request);
            }

            abort(401, 'Invalid webhook bearer token.');
        }

        abort(401, 'Missing webhook authentication.');
    }
}
```

Notes for the implementer:

- Do **not** attempt Bearer when `X-Webhook-Signature` is present but invalid.
- Use `hash_equals` for both comparisons.
- Do not log secret/token values.

- [ ] **Step 2: Re-run dual-auth tests — expect PASS**

Run:

```bash
php artisan test tests/Feature/Webhooks/WebhookVerificationTest.php
```

Expected: all tests in that file PASS (old HMAC + new Bearer / dual / 500).

Note: Bearer cases still send `X-Event-Id` so idempotency middleware does not block yet.

- [ ] **Step 3: Commit (when user asks)**

```bash
git add app/Http/Middleware/VerifyWebhookSignature.php tests/Feature/Webhooks/WebhookVerificationTest.php
git commit -m "$(cat <<'EOF'
feat(webhooks): accept Bearer fallback when HMAC signature header is absent

EOF
)"
```

---

### Task 3: Failing tests — Green Bearer + derived idempotency + state update

**Files:**
- Create: `tests/Feature/Webhooks/WebhookGreenBearerTest.php`

- [ ] **Step 1: Write Feature tests that fail on missing `X-Event-Id` today**

Create `tests/Feature/Webhooks/WebhookGreenBearerTest.php`:

```php
<?php

use App\Models\Integration\Instancia;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['services.webhooks.secret' => 'test-webhook-secret']);
    Cache::flush();
});

test('green bearer stateInstanceChanged updates instancia without hmac or event id header', function () {
    $instancia = Instancia::factory()->create([
        'id_instance' => '1101234567',
        'status' => 'waiting_qr',
        'green_state' => 'notAuthorized',
        'authorized_at' => null,
    ]);

    $payload = [
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => [
            'idInstance' => 1101234567,
        ],
        'stateInstance' => 'authorized',
        'timestamp' => 1720000000,
        'idMessage' => 'green-msg-001',
    ];
    $body = json_encode($payload);

    $this->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
        ],
        $body,
    )->assertOk()->assertJson(['received' => true, 'duplicate' => false]);

    $instancia->refresh();

    expect($instancia->green_state)->toBe('authorized')
        ->and($instancia->status)->toBe('authorized')
        ->and($instancia->authorized_at)->not->toBeNull();
});

test('green bearer duplicate delivery is idempotent using derived event id', function () {
    Instancia::factory()->create([
        'id_instance' => '1109998887',
        'status' => 'waiting_qr',
        'green_state' => 'notAuthorized',
    ]);

    $payload = [
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 1109998887],
        'stateInstance' => 'authorized',
        'timestamp' => 1720000001,
        'idMessage' => 'green-dup-001',
    ];
    $body = json_encode($payload);
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
    ];

    $this->call('POST', route('api.v1.webhooks.incoming'), [], [], [], $server, $body)
        ->assertOk()
        ->assertJson(['duplicate' => false]);

    $this->call('POST', route('api.v1.webhooks.incoming'), [], [], [], $server, $body)
        ->assertOk()
        ->assertJson(['received' => true, 'duplicate' => true]);
});

test('green bearer without ids still accepts via body hash idempotency key', function () {
    $payload = [
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => '1100000001'],
        'stateInstance' => 'notAuthorized',
    ];
    $body = json_encode($payload);

    $this->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
        ],
        $body,
    )->assertOk()->assertJson(['received' => true, 'duplicate' => false]);
});
```

- [ ] **Step 2: Run — expect FAIL (422 Missing X-Event-Id)**

Run:

```bash
php artisan test tests/Feature/Webhooks/WebhookGreenBearerTest.php
```

Expected: FAIL with 422 `Missing X-Event-Id header` (auth Bearer already works after Task 2; idempotency still blocks).

- [ ] **Step 3: Commit failing tests (when user asks)**

```bash
git add tests/Feature/Webhooks/WebhookGreenBearerTest.php
git commit -m "$(cat <<'EOF'
test(webhooks): add Green Bearer state and idempotency cases

EOF
)"
```

---

### Task 4: Implement derived event-id in `WebhookIdempotency`

**Files:**
- Modify: `app/Http/Middleware/WebhookIdempotency.php`

- [ ] **Step 1: Replace middleware with header / payload / body-hash resolution**

Replace `app/Http/Middleware/WebhookIdempotency.php` with:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class WebhookIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $eventId = $this->resolveEventId($request);
        $cacheKey = 'webhook:event:'.sha1($eventId);

        if (Cache::has($cacheKey)) {
            return response()->json([
                'received' => true,
                'duplicate' => true,
            ]);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            Cache::put($cacheKey, true, now()->addDay());
        }

        return $response;
    }

    private function resolveEventId(Request $request): string
    {
        $header = $request->header('X-Event-Id');

        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        $payload = $request->all();

        $idMessage = $payload['idMessage'] ?? null;
        if ($idMessage !== null && $idMessage !== '') {
            return (string) $idMessage;
        }

        $idWebhook = $payload['idWebhook'] ?? null;
        if ($idWebhook !== null && $idWebhook !== '') {
            return (string) $idWebhook;
        }

        $typeWebhook = (string) ($payload['typeWebhook'] ?? '');
        $instanceData = is_array($payload['instanceData'] ?? null) ? $payload['instanceData'] : [];
        $idInstance = (string) ($instanceData['idInstance'] ?? $payload['idInstance'] ?? '');
        $timestamp = $payload['timestamp'] ?? null;

        if ($typeWebhook !== '' && $idInstance !== '' && $timestamp !== null && $timestamp !== '') {
            return $typeWebhook.'|'.$idInstance.'|'.(string) $timestamp;
        }

        return sha1($request->getContent());
    }
}
```

Important:

- Prefer header → `idMessage` → `idWebhook` → composed triple → `sha1(body)`.
- Do **not** abort 422 for missing header anymore.
- Existing HMAC tests that send `X-Event-Id` must keep using the header path (same cache key scheme: `sha1(eventId)`).

- [ ] **Step 2: Run Green + verification suites — expect PASS**

Run:

```bash
php artisan test tests/Feature/Webhooks/
```

Expected: all webhook Feature tests PASS.

- [ ] **Step 3: Commit (when user asks)**

```bash
git add app/Http/Middleware/WebhookIdempotency.php tests/Feature/Webhooks/WebhookGreenBearerTest.php
git commit -m "$(cat <<'EOF'
feat(webhooks): derive idempotency keys for Green payloads without X-Event-Id

EOF
)"
```

---

### Task 5: Docs + Scribe comment + env/deploy clarification

**Files:**
- Modify: `docs/integration/waapi-api-contract.md` (webhooks section ~374–382)
- Modify: `app/Http/Controllers/Api/V1/IncomingWebhookController.php` (class/method docblocks)
- Modify: `.env.example` (Green / webhook secret comments)
- Modify: `docs/DEPLOY.md` (`WEBHOOK_SECRET` / Green token lines)

- [ ] **Step 1: Update contract section**

Replace the webhooks table/auth blurb in `docs/integration/waapi-api-contract.md` with:

```markdown
## Webhooks entrantes (Green API → api)

**No consumidos por waapi.** Green API (y otros emisores firmados) envían eventos a api.

| Method | Path | Auth |
|--------|------|------|
| POST | `/api/v1/webhooks/incoming` | **HMAC** header `X-Webhook-Signature` = `HMAC-SHA256(rawBody, WEBHOOK_SECRET)`, **or** `Authorization: Bearer {WEBHOOK_SECRET}` when the signature header is absent |

Idempotencia:

- Prefer header `X-Event-Id` when present.
- Otherwise api derives a key from Green payload fields (`idMessage` / `idWebhook` / `typeWebhook|idInstance|timestamp`) or `sha1(rawBody)`.

Same `WEBHOOK_SECRET` is configured on Green instances as `webhookUrlToken` (Green sends it back as Bearer).

Invalid HMAC does **not** fall back to Bearer on the same request.
```

- [ ] **Step 2: Update controller Scribe blurb**

In `IncomingWebhookController`, change the class/group docs to mention dual auth, e.g.:

- Class: `Incoming webhook endpoints (HMAC signature or Bearer token + idempotency).`
- Method: note that Green may authenticate with `Authorization: Bearer` and omit `X-Event-Id`.

Do not change controller business logic.

- [ ] **Step 3: Fix `.env.example` drift**

Update the Green / webhook block so it is clear there is **one** shared secret:

```dotenv
GREEN_API_INSTANCE=
GREEN_API_TOKEN=
GREEN_API_PARTNER_TOKEN=
GREEN_API_WEBHOOK_URL="${APP_URL}/api/v1/webhooks/incoming"

# Shared webhook secret: HMAC X-Webhook-Signature AND Green webhookUrlToken (Bearer)
WEBHOOK_SECRET=

# Deprecated alias — leave empty; use WEBHOOK_SECRET (mapped in config/services.php)
# GREEN_API_WEBHOOK_TOKEN=
```

If `GREEN_API_WEBHOOK_TOKEN` must remain for ops continuity, keep the key but comment that it is unused by `config/services.php` (which already uses `WEBHOOK_SECRET` for `green_api.webhook_secret`). Do **not** introduce a second live secret in v1.

- [ ] **Step 4: Update `docs/DEPLOY.md`**

Near `WEBHOOK_SECRET=<generar-secreto-hmac>` and `GREEN_API_WEBHOOK_TOKEN=`:

- State that `WEBHOOK_SECRET` is required for both HMAC clients and Green `webhookUrlToken` / Bearer.
- Mark `GREEN_API_WEBHOOK_TOKEN` as unused / legacy; use `WEBHOOK_SECRET` only.

- [ ] **Step 5: Commit docs (when user asks)**

```bash
git add docs/integration/waapi-api-contract.md app/Http/Controllers/Api/V1/IncomingWebhookController.php .env.example docs/DEPLOY.md
git commit -m "$(cat <<'EOF'
docs(webhooks): document HMAC primary and Bearer fallback for Green incoming

EOF
)"
```

---

### Task 6: Full verification + mark spec approved status (optional)

**Files:**
- Optional modify: `docs/superpowers/specs/2026-07-14-webhook-dual-auth-green-api-design.md` (Estado → Implementado / listo para review)

- [ ] **Step 1: Run full test suite (or at least webhooks + nearby Feature)**

Run:

```bash
php artisan test tests/Feature/Webhooks/
php artisan test
```

Expected: webhook tests green; no new failures outside unrelated known CI issues (upload/scribe) on your branch.

- [ ] **Step 2: Manual checklist against acceptance criteria**

- [ ] Green-style Bearer POST without HMAC / without `X-Event-Id` → 200 and can authorize instancia
- [ ] Existing HMAC + `X-Event-Id` still 200
- [ ] Invalid HMAC + valid Bearer same request → 401
- [ ] Missing both auths → 401
- [ ] Empty secret → 500
- [ ] Docs updated

- [ ] **Step 3: Do not deploy** unless user explicitly requests. Post-merge smoke on VPS is human ops (curl Bearer against a real provisioned instance).

---

## Spec coverage checklist

| Spec requirement | Task |
|------------------|------|
| HMAC primary, keep existing path | 1, 2 |
| Bearer fallback when signature header absent | 1, 2 |
| Invalid HMAC → no Bearer fallback | 1, 2 |
| Empty secret → 500 | 1, 2 |
| Shared `WEBHOOK_SECRET` | 2, 5 |
| Idempotency: header / idMessage / idWebhook / compose / sha1(body) | 3, 4 |
| No 422 for missing `X-Event-Id` on Green path | 3, 4 |
| `stateInstanceChanged` updates instancia via Bearer | 3 |
| Same route / no new endpoint / no provisioning change | (n/a code) |
| Contract + DEPLOY + env docs | 5 |
| Out of scope: Basic, int_webhooks, new types, CI/PR#13, VPS | excluded |

---

## Out of scope (do not implement in this plan)

- `int_webhooks` persistence
- Processing `incomingMessageReceived` / other `typeWebhook`
- Green Basic Auth
- CI PHP version / PR #13
- VPS deploy or live smoke against Green
