# Webhook Secret Dual Rotation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Accept current + previous `WEBHOOK_SECRET` without downtime, and ship `webhooks:rotate-secret` (verify by default; explicit flags to write `.env` / push Green / clear previous) with WhatsApp success + email on probe failure.

**Architecture:** Extend `VerifyWebhookSignature` to validate HMAC/Bearer against a list of secrets. Skip-persist probes whose `X-Event-Id` starts with `rotate-probe-`. A hybrid Artisan command orchestrates env mutation, Green `setSettings`, HTTP probe, and ops notifications. Cron is documented only (verify mode); no scheduler registration in v1.

**Tech Stack:** Laravel 13, Pest, `Illuminate\Support\Facades\Http`, `Mail`, `InstanceClient::setSettings`, `MessageSendService::queueOutbound`, Horizon mail env `HORIZON_MAIL`.

**Spec:** [`docs/superpowers/specs/2026-07-23-webhook-secret-rotation-dual-secret-design.md`](../specs/2026-07-23-webhook-secret-rotation-dual-secret-design.md)

## Global Constraints

- Do **not** change tenant/instance `publicId` or Sanctum client tokens.
- Never log or send `WEBHOOK_SECRET` / tokens in WhatsApp, mail, or log context.
- Empty `WEBHOOK_SECRET` (current) still yields HTTP 500 on webhook ingress.
- Invalid HMAC header must **not** fall back to Bearer on the same request.
- `--write-env` / `--apply-green` / `--clear-previous` only run when the flag is present.
- `--dry-run`: no `.env` writes, no Green calls, no probe, no WA/mail (log intended actions only).
- Do **not** register this command on the Laravel scheduler in v1.
- Prefer small focused classes under `app/Services/Webhooks/` and `app/Support/`.
- **Config cache gate (prod):** if `app()->configurationIsCached()` and (`--write-env` or `--clear-previous`) without `--cache-config` → abort **before** mutating `.env` with FAILURE + clear message. Probe hits PHP-FPM; without `config:cache`, FPM never sees `PREVIOUS` → false 401s / broken grace window.
- **Flag conflict:** `--write-env` and `--clear-previous` together → FAILURE (no mutate). Clearing PREVIOUS in the same run as a rotate destroys zero-downtime.
- **`apply-green` query:** skip `status = deleted`; require non-empty `id_instance` + `api_token_instance` (same eligibility as `green:apply-send-delay` plan).
- **`apply-green` payload:** include the same webhook fields as `ProvisionGreenInstanceJob`. If that job already sets `delaySendMessagesMilliseconds`, include `15000` here too so rotation does not regress Yellow Card mitigation when Green replaces settings.
- **Mail sync:** `WebhookSecretVerifyFailedMail` must **not** implement `ShouldQueue` (command exit code must reflect mail attempt; sync `Mail::send`).
- **OPS WhatsApp:** `MessageSendService::queueOutbound` calls `refreshFromGreen`, module guard, and quota. Ops instance must be `authorized`, WhatsApp module enabled, quota free. WA failure after probe OK → log + optional mail; verify still SUCCESS.
- **Probe URL:** `rtrim(config('services.ops.probe_base_url') ?: config('app.url'), '/')` + `/api/v1/webhooks/incoming`. Prod `APP_URL` must be reachable from the VPS itself (loopback/public). Override only for tests via `services.ops.probe_base_url`.

## File structure

| File | Responsibility |
|------|----------------|
| `config/services.php` | `webhooks.previous_secret` + `services.ops.*` |
| `.env.example` | `WEBHOOK_SECRET_PREVIOUS`, `OPS_ALERT_*` |
| `app/Http/Middleware/VerifyWebhookSignature.php` | Dual-secret HMAC/Bearer |
| `app/Http/Controllers/Api/V1/IncomingWebhookController.php` | Skip persist for `rotate-probe-*` |
| `app/Support/EnvFileKeyWriter.php` | Atomic upsert/clear keys in `.env` |
| `app/Services/Webhooks/WebhookSecretProbe.php` | HTTP probe current (+ previous) |
| `app/Services/Webhooks/WebhookRotationNotifier.php` | WA via MessageSendService + mail |
| `app/Console/Commands/RotateWebhookSecretCommand.php` | Orchestrate flags + gates |
| `app/Mail/WebhookSecretVerifyFailedMail.php` | Failure mail (sync) |
| `resources/views/mail/webhook-secret-verify-failed.blade.php` | Mail body |
| `tests/Feature/Webhooks/WebhookPreviousSecretTest.php` | Dual-auth cases |
| `tests/Feature/Webhooks/WebhookRotateProbeSkipTest.php` | Probe skip-persist |
| `tests/Feature/Console/RotateWebhookSecretCommandTest.php` | Command verify / dry-run / flags / gates |
| `tests/Unit/Support/EnvFileKeyWriterTest.php` | Env writer |
| `tests/Unit/Services/Webhooks/WebhookSecretProbeTest.php` | Probe |
| `tests/Unit/Services/Webhooks/WebhookRotationNotifierTest.php` | Notifier |
| `docs/DEPLOY.md` | Runbook + cron note + post-deploy gate |
| Spec header | Link to this plan |

---

### Task 1: Config + dual-secret middleware

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`
- Modify: `app/Http/Middleware/VerifyWebhookSignature.php`
- Create: `tests/Feature/Webhooks/WebhookPreviousSecretTest.php`
- Keep existing: `tests/Feature/Webhooks/WebhookVerificationTest.php` green

**Interfaces:**
- Consumes: `config('services.webhooks.secret')`, `config('services.webhooks.previous_secret')`
- Produces: middleware accepts current or previous; request attribute `webhook_used_previous_secret` (bool); `config('services.ops.*')` keys for later tasks

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Webhooks/WebhookPreviousSecretTest.php`:

```php
<?php

beforeEach(function () {
    config([
        'services.webhooks.secret' => 'current-secret',
        'services.webhooks.previous_secret' => 'previous-secret',
    ]);
});

test('webhook accepts bearer with previous secret', function () {
    $body = json_encode(['event' => 'message.received']);

    $this->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Event-Id' => 'evt-prev-bearer-001',
            'HTTP_AUTHORIZATION' => 'Bearer previous-secret',
        ],
        $body,
    )->assertOk()->assertJson(['received' => true]);
});

test('webhook accepts hmac signed with previous secret', function () {
    $payload = ['event' => 'message.received'];
    $body = json_encode($payload);
    $signature = hash_hmac('sha256', $body, 'previous-secret');

    $this->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Event-Id' => 'evt-prev-hmac-001',
            'HTTP_X-Webhook-Signature' => $signature,
        ],
        $body,
    )->assertOk();
});

test('webhook rejects bearer that matches neither secret', function () {
    $this->postJson(route('api.v1.webhooks.incoming'), ['event' => 'x'], [
        'X-Event-Id' => 'evt-prev-bad-001',
        'Authorization' => 'Bearer neither-secret',
    ])->assertUnauthorized();
});

test('invalid hmac does not fall back to previous bearer', function () {
    $body = json_encode(['event' => 'message.received']);

    $this->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Event-Id' => 'evt-prev-hmac-no-fallback',
            'HTTP_X-Webhook-Signature' => 'invalid',
            'HTTP_AUTHORIZATION' => 'Bearer previous-secret',
        ],
        $body,
    )->assertUnauthorized();
});

test('empty previous secret only accepts current', function () {
    config(['services.webhooks.previous_secret' => '']);

    $this->postJson(route('api.v1.webhooks.incoming'), ['event' => 'x'], [
        'X-Event-Id' => 'evt-prev-empty-001',
        'Authorization' => 'Bearer previous-secret',
    ])->assertUnauthorized();

    $this->postJson(route('api.v1.webhooks.incoming'), ['event' => 'x'], [
        'X-Event-Id' => 'evt-prev-empty-002',
        'Authorization' => 'Bearer current-secret',
    ])->assertOk();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Webhooks/WebhookPreviousSecretTest.php`

Expected: FAIL (previous bearer/HMAC rejected with 401)

- [ ] **Step 3: Update config and .env.example**

In `config/services.php`, replace the `webhooks` block and append `ops`:

```php
    'webhooks' => [
        'secret' => env('WEBHOOK_SECRET'),
        'previous_secret' => env('WEBHOOK_SECRET_PREVIOUS'),
    ],

    'ops' => [
        'alert_mail' => env('HORIZON_MAIL'),
        'alert_whatsapp_numbers' => env('OPS_ALERT_WHATSAPP_NUMBERS'),
        'alert_instance_public_id' => env('OPS_ALERT_INSTANCE_PUBLIC_ID'),
        // Absolute path override for tests; production leaves null → base_path('.env')
        'env_path' => env('WEBHOOK_ROTATE_ENV_PATH'),
        // Optional probe base (tests); production leaves null → config('app.url')
        'probe_base_url' => env('WEBHOOK_PROBE_BASE_URL'),
    ],
```

Leave `'webhook_secret' => env('WEBHOOK_SECRET')` under `green_api` unchanged (current only).

In `.env.example`, after `WEBHOOK_SECRET=`, add:

```env
# Gracia post-rotación (Bearer/HMAC viejo aceptado mientras Green migra)
WEBHOOK_SECRET_PREVIOUS=

# Ops alerts for webhooks:rotate-secret verify
OPS_ALERT_WHATSAPP_NUMBERS=
OPS_ALERT_INSTANCE_PUBLIC_ID=
```

Do **not** add `WEBHOOK_ROTATE_ENV_PATH` / `WEBHOOK_PROBE_BASE_URL` to `.env.example` (test-only overrides via `config([...])`).

- [ ] **Step 4: Implement dual-secret middleware**

Replace `app/Http/Middleware/VerifyWebhookSignature.php` with logic equivalent to:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secrets = $this->secrets();

        if ($secrets === []) {
            abort(500, 'Webhook secret is not configured.');
        }

        $signature = $request->header('X-Webhook-Signature', '');

        if (is_string($signature) && $signature !== '') {
            $raw = $request->getContent();
            foreach ($secrets as $index => $secret) {
                $expected = hash_hmac('sha256', $raw, $secret);
                if (hash_equals($expected, $signature)) {
                    return $this->accept($request, $next, 'hmac', $index > 0);
                }
            }

            $this->reject($request, 'hmac', 'invalid_signature', 'Invalid webhook signature.');
        }

        $authorization = $request->header('Authorization', '');
        $authorization = is_string($authorization) ? trim($authorization) : '';

        if ($authorization === '') {
            $this->reject($request, 'none', 'missing_credentials', 'Missing webhook authentication.');
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches) !== 1) {
            $this->reject($request, 'none', 'unsupported_authorization', 'Unsupported webhook authorization header.');
        }

        foreach ($secrets as $index => $secret) {
            if (hash_equals($secret, $matches[1])) {
                return $this->accept($request, $next, 'bearer', $index > 0);
            }
        }

        $this->reject($request, 'bearer', 'invalid_bearer_token', 'Invalid webhook bearer token.');
    }

    /** @return list<string> */
    private function secrets(): array
    {
        $current = config('services.webhooks.secret');
        if (! is_string($current) || $current === '') {
            return [];
        }

        $secrets = [$current];
        $previous = config('services.webhooks.previous_secret');
        if (is_string($previous) && $previous !== '' && ! hash_equals($current, $previous)) {
            $secrets[] = $previous;
        }

        return $secrets;
    }

    private function accept(Request $request, Closure $next, string $mode, bool $usedPrevious): Response
    {
        $request->attributes->set('webhook_auth_mode', $mode);
        $request->attributes->set('webhook_used_previous_secret', $usedPrevious);

        Log::info('Webhook authenticated.', [
            'webhook_auth_mode' => $mode,
            'used_previous_secret' => $usedPrevious,
            'path' => $request->path(),
        ]);

        return $next($request);
    }

    private function reject(Request $request, string $mode, string $reason, string $message): never
    {
        Log::warning('Webhook authentication rejected.', [
            'webhook_auth_mode' => $mode,
            'reason' => $reason,
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        abort(401, $message);
    }
}
```

- [ ] **Step 5: Run dual-secret + existing webhook tests**

Run:

```bash
php artisan test tests/Feature/Webhooks/WebhookPreviousSecretTest.php tests/Feature/Webhooks/WebhookVerificationTest.php
```

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add config/services.php .env.example app/Http/Middleware/VerifyWebhookSignature.php tests/Feature/Webhooks/WebhookPreviousSecretTest.php
git commit -m "$(cat <<'EOF'
feat(webhooks): accept WEBHOOK_SECRET_PREVIOUS for zero-downtime rotation

EOF
)"
```

---

### Task 2: Skip-persist for rotate probes

**Files:**
- Modify: `app/Http/Controllers/Api/V1/IncomingWebhookController.php`
- Create: `tests/Feature/Webhooks/WebhookRotateProbeSkipTest.php`

**Interfaces:**
- Consumes: header `X-Event-Id` (string)
- Produces: JSON `{received:true, probe:true}` without `Webhook::create` / job dispatch when id starts with `rotate-probe-`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\ProcessWebhookJob;
use App\Models\Integration\Webhook;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.webhooks.secret' => 'test-webhook-secret']);
});

test('rotate-probe event id skips persistence and job dispatch', function () {
    Queue::fake();

    $body = json_encode([
        'typeWebhook' => 'incomingMessageReceived',
        'idInstance' => '0',
        'timestamp' => 0,
    ]);

    $this->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Event-Id' => 'rotate-probe-01HXTEST',
            'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
        ],
        $body,
    )->assertOk()->assertJson(['received' => true, 'probe' => true]);

    expect(Webhook::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Webhooks/WebhookRotateProbeSkipTest.php`

Expected: FAIL (persists row / missing `probe` key)

- [ ] **Step 3: Implement skip at top of controller**

In `IncomingWebhookController::__invoke`, immediately after the method opens:

```php
        $eventHeader = $request->header('X-Event-Id', '');
        if (is_string($eventHeader) && str_starts_with($eventHeader, 'rotate-probe-')) {
            return response()->json(['received' => true, 'probe' => true]);
        }
```

Keep the rest of the method unchanged (still uses `WebhookEventId::resolve` for normal traffic). Auth still runs via `webhook.signature` middleware before the controller.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Webhooks/WebhookRotateProbeSkipTest.php`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/V1/IncomingWebhookController.php tests/Feature/Webhooks/WebhookRotateProbeSkipTest.php
git commit -m "$(cat <<'EOF'
feat(webhooks): skip persist for rotate-probe event ids

EOF
)"
```

---

### Task 3: EnvFileKeyWriter (atomic)

**Files:**
- Create: `app/Support/EnvFileKeyWriter.php`
- Create: `tests/Unit/Support/EnvFileKeyWriterTest.php`

**Interfaces:**
- Consumes: absolute path to `.env` file
- Produces:
  - `set(string $key, string $value): void` — replace existing `KEY=...` line or append
  - `clear(string $key): void` — set `KEY=` (empty value), create line if missing
- Writes via temp file in the same directory + `rename()` (atomic on same filesystem) to avoid truncated `.env` on crash/disk full.

- [ ] **Step 1: Write the failing unit test**

```php
<?php

use App\Support\EnvFileKeyWriter;

test('EnvFileKeyWriter sets and clears keys without touching others', function () {
    $path = sys_get_temp_dir().'/lebytek-env-writer-'.uniqid('', true);
    file_put_contents($path, "APP_NAME=Lebytek\nWEBHOOK_SECRET=old\n");

    $writer = new EnvFileKeyWriter($path);
    $writer->set('WEBHOOK_SECRET', 'new-secret');
    $writer->set('WEBHOOK_SECRET_PREVIOUS', 'old');
    $writer->clear('WEBHOOK_SECRET_PREVIOUS');

    $contents = file_get_contents($path);
    expect($contents)->toContain('APP_NAME=Lebytek')
        ->and($contents)->toContain('WEBHOOK_SECRET=new-secret')
        ->and($contents)->toMatch('/^WEBHOOK_SECRET_PREVIOUS=$/m');

    unlink($path);
});

test('EnvFileKeyWriter quotes values with spaces', function () {
    $path = sys_get_temp_dir().'/lebytek-env-writer-'.uniqid('', true);
    file_put_contents($path, "FOO=bar\n");

    (new EnvFileKeyWriter($path))->set('FOO', 'has space');

    expect(file_get_contents($path))->toMatch('/^FOO="has space"$/m');
    unlink($path);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Support/EnvFileKeyWriterTest.php`

Expected: FAIL (class not found)

- [ ] **Step 3: Implement writer**

```php
<?php

namespace App\Support;

final class EnvFileKeyWriter
{
    public function __construct(
        private readonly string $path,
    ) {}

    public function set(string $key, string $value): void
    {
        $this->upsert($key, $value);
    }

    public function clear(string $key): void
    {
        $this->upsert($key, '');
    }

    private function upsert(string $key, string $value): void
    {
        if (! is_file($this->path)) {
            throw new \RuntimeException("Env file not found: {$this->path}");
        }

        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new \RuntimeException("Unable to read env file: {$this->path}");
        }

        $line = $key.'='.$this->escape($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            $contents = preg_replace($pattern, $line, $contents, 1);
            if (! is_string($contents)) {
                throw new \RuntimeException("Unable to update env key: {$key}");
            }
        } else {
            $contents = rtrim($contents, "\r\n").PHP_EOL.$line.PHP_EOL;
        }

        $dir = dirname($this->path);
        $temp = tempnam($dir, 'env.');
        if ($temp === false) {
            throw new \RuntimeException("Unable to create temp env file in: {$dir}");
        }

        if (file_put_contents($temp, $contents) === false) {
            @unlink($temp);
            throw new \RuntimeException("Unable to write temp env file: {$temp}");
        }

        if (! rename($temp, $this->path)) {
            @unlink($temp);
            throw new \RuntimeException("Unable to replace env file: {$this->path}");
        }
    }

    private function escape(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/\s|#|"|\'/', $value) === 1) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Support/EnvFileKeyWriterTest.php`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Support/EnvFileKeyWriter.php tests/Unit/Support/EnvFileKeyWriterTest.php
git commit -m "$(cat <<'EOF'
feat(support): add atomic EnvFileKeyWriter for webhook secret rotation

EOF
)"
```

---

### Task 4: Probe + notifier services

**Files:**
- Create: `app/Services/Webhooks/WebhookSecretProbe.php`
- Create: `app/Services/Webhooks/WebhookRotationNotifier.php`
- Create: `app/Mail/WebhookSecretVerifyFailedMail.php`
- Create: `resources/views/mail/webhook-secret-verify-failed.blade.php`
- Create: `tests/Unit/Services/Webhooks/WebhookSecretProbeTest.php`
- Create: `tests/Unit/Services/Webhooks/WebhookRotationNotifierTest.php`

**Interfaces:**
- `WebhookSecretProbe::probe(): array{ok: bool, results: list<array{label: string, status: int|null, error: string|null}>}`
- `WebhookRotationNotifier::notifySuccess(string $summary): void`
- `WebhookRotationNotifier::notifyFailure(string $summary): void`

- [ ] **Step 1: Write failing probe tests**

Create `tests/Unit/Services/Webhooks/WebhookSecretProbeTest.php`:

```php
<?php

use App\Services\Webhooks\WebhookSecretProbe;
use Illuminate\Support\Facades\Http;

test('WebhookSecretProbe posts bearer probes for current and previous', function () {
    config([
        'app.url' => 'https://api.test',
        'services.ops.probe_base_url' => null,
        'services.webhooks.secret' => 'cur',
        'services.webhooks.previous_secret' => 'prev',
    ]);

    Http::fake([
        'https://api.test/api/v1/webhooks/incoming' => Http::sequence()
            ->push(['received' => true, 'probe' => true], 200)
            ->push(['received' => true, 'probe' => true], 200),
    ]);

    $result = app(WebhookSecretProbe::class)->probe();

    expect($result['ok'])->toBeTrue()
        ->and($result['results'])->toHaveCount(2);

    Http::assertSentCount(2);
});

test('WebhookSecretProbe fails when any probe is not 2xx', function () {
    config([
        'app.url' => 'https://api.test',
        'services.ops.probe_base_url' => null,
        'services.webhooks.secret' => 'cur',
        'services.webhooks.previous_secret' => '',
    ]);

    Http::fake([
        'https://api.test/api/v1/webhooks/incoming' => Http::response(['message' => 'no'], 401),
    ]);

    $result = app(WebhookSecretProbe::class)->probe();

    expect($result['ok'])->toBeFalse();
});

test('WebhookSecretProbe uses probe_base_url override', function () {
    config([
        'app.url' => 'https://ignored.test',
        'services.ops.probe_base_url' => 'http://127.0.0.1:9999',
        'services.webhooks.secret' => 'cur',
        'services.webhooks.previous_secret' => '',
    ]);

    Http::fake([
        'http://127.0.0.1:9999/api/v1/webhooks/incoming' => Http::response(['received' => true, 'probe' => true], 200),
    ]);

    expect(app(WebhookSecretProbe::class)->probe()['ok'])->toBeTrue();
});
```

- [ ] **Step 2: Run probe tests — expect FAIL**

Run: `php artisan test tests/Unit/Services/Webhooks/WebhookSecretProbeTest.php`

Expected: FAIL (class missing)

- [ ] **Step 3: Implement WebhookSecretProbe**

```php
<?php

namespace App\Services\Webhooks;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class WebhookSecretProbe
{
    /**
     * @return array{ok: bool, results: list<array{label: string, status: int|null, error: string|null}>}
     */
    public function probe(): array
    {
        $results = [];
        $current = config('services.webhooks.secret');
        if (! is_string($current) || $current === '') {
            return [
                'ok' => false,
                'results' => [['label' => 'current', 'status' => null, 'error' => 'WEBHOOK_SECRET empty']],
            ];
        }

        $results[] = $this->probeWith('current', $current);

        $previous = config('services.webhooks.previous_secret');
        if (is_string($previous) && $previous !== '') {
            $results[] = $this->probeWith('previous', $previous);
        }

        $ok = collect($results)->every(
            fn (array $row) => is_int($row['status']) && $row['status'] >= 200 && $row['status'] < 300
        );

        return ['ok' => $ok, 'results' => $results];
    }

    /** @return array{label: string, status: int|null, error: string|null} */
    private function probeWith(string $label, string $secret): array
    {
        $base = config('services.ops.probe_base_url');
        if (! is_string($base) || $base === '') {
            $base = (string) config('app.url');
        }
        $url = rtrim($base, '/').'/api/v1/webhooks/incoming';
        $eventId = 'rotate-probe-'.(string) Str::ulid();

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$secret,
                    'X-Event-Id' => $eventId,
                    'Accept' => 'application/json',
                ])
                ->asJson()
                ->post($url, [
                    'typeWebhook' => 'incomingMessageReceived',
                    'idInstance' => '0',
                    'timestamp' => 0,
                    'messageData' => [
                        'typeMessage' => 'textMessage',
                        'textMessageData' => ['textMessage' => 'webhook-secret-rotate-probe'],
                    ],
                ]);

            return [
                'label' => $label,
                'status' => $response->status(),
                'error' => $response->successful() ? null : 'http_'.$response->status(),
            ];
        } catch (\Throwable $e) {
            return [
                'label' => $label,
                'status' => null,
                'error' => 'request_failed',
            ];
        }
    }
}
```

- [ ] **Step 4: Write failing notifier tests**

Create `tests/Unit/Services/Webhooks/WebhookRotationNotifierTest.php`:

```php
<?php

use App\Mail\WebhookSecretVerifyFailedMail;
use App\Models\Integration\Instancia;
use App\Services\Messaging\MessageSendService;
use App\Services\Webhooks\WebhookRotationNotifier;
use Illuminate\Support\Facades\Mail;

test('notifyFailure sends mail to services.ops.alert_mail', function () {
    Mail::fake();
    config(['services.ops.alert_mail' => 'ops@example.com']);

    app(WebhookRotationNotifier::class)->notifyFailure('probe failed status=401');

    Mail::assertSent(WebhookSecretVerifyFailedMail::class, function (WebhookSecretVerifyFailedMail $mail) {
        return $mail->hasTo('ops@example.com');
    });
});

test('notifyFailure logs when alert_mail empty', function () {
    Mail::fake();
    config(['services.ops.alert_mail' => '']);

    app(WebhookRotationNotifier::class)->notifyFailure('probe failed');

    Mail::assertNothingSent();
});

test('notifySuccess queues whatsapp when ops instance configured', function () {
    config([
        'services.ops.alert_mail' => 'ops@example.com',
        'services.ops.alert_whatsapp_numbers' => '5215550001111',
        'services.ops.alert_instance_public_id' => '01TESTINSTANCE',
    ]);

    $instancia = Instancia::factory()->authorized()->create([
        'public_id' => '01TESTINSTANCE',
    ]);

    $mock = Mockery::mock(MessageSendService::class);
    $mock->shouldReceive('queueOutbound')
        ->once()
        ->withArgs(function (int $tenantId, $inst, string $recipient, string $body, string $key) use ($instancia) {
            return $tenantId === (int) $instancia->tenant_id
                && $inst->is($instancia)
                && $recipient === '5215550001111'
                && str_contains($body, 'webhook secret verify OK')
                && str_starts_with($key, 'webhook-rotate-ok-');
        })
        ->andReturn(['mensaje' => new \App\Models\Integration\Mensaje, 'created' => true]);

    $this->app->instance(MessageSendService::class, $mock);

    app(WebhookRotationNotifier::class)->notifySuccess('webhook secret verify OK');
});
```

- [ ] **Step 5: Implement mail + notifier**

`app/Mail/WebhookSecretVerifyFailedMail.php` — **do not** implement `ShouldQueue`:

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WebhookSecretVerifyFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $summary,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[api] webhook secret verify failed',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.webhook-secret-verify-failed',
        );
    }
}
```

Create `resources/views/mail/webhook-secret-verify-failed.blade.php`:

```blade
Webhook secret verify failed.

{{ $summary }}

Host: {{ gethostname() }}
Time: {{ now()->toIso8601String() }}
```

`app/Services/Webhooks/WebhookRotationNotifier.php`:

```php
<?php

namespace App\Services\Webhooks;

use App\Mail\WebhookSecretVerifyFailedMail;
use App\Models\Integration\Instancia;
use App\Services\Messaging\MessageSendService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class WebhookRotationNotifier
{
    public function __construct(
        private readonly MessageSendService $messageSendService,
    ) {}

    public function notifySuccess(string $summary): void
    {
        $numbers = $this->numbers();
        $publicId = config('services.ops.alert_instance_public_id');

        if ($numbers === [] || ! is_string($publicId) || $publicId === '') {
            Log::warning('Webhook rotation success: OPS_ALERT_* not configured; skipping WhatsApp.');

            return;
        }

        $instancia = Instancia::query()
            ->withoutGlobalScope('tenant')
            ->where('public_id', $publicId)
            ->first();

        if ($instancia === null) {
            Log::warning('Webhook rotation success: ops instance not found.', [
                'public_id' => $publicId,
            ]);

            return;
        }

        $body = $summary.' @ '.now()->toIso8601String().' host='.gethostname();

        foreach ($numbers as $number) {
            try {
                $this->messageSendService->queueOutbound(
                    (int) $instancia->tenant_id,
                    $instancia,
                    $number,
                    $body,
                    'webhook-rotate-ok-'.(string) Str::ulid(),
                );
            } catch (\Throwable $e) {
                Log::error('Webhook rotation WhatsApp notify failed.', [
                    'error' => $e->getMessage(),
                ]);
                $mail = config('services.ops.alert_mail');
                if (is_string($mail) && $mail !== '') {
                    Mail::to($mail)->send(new WebhookSecretVerifyFailedMail(
                        'verify OK but WhatsApp failed: '.$e->getMessage()
                    ));
                }
            }
        }
    }

    public function notifyFailure(string $summary): void
    {
        $mail = config('services.ops.alert_mail');
        if (! is_string($mail) || $mail === '') {
            Log::error('Webhook rotation verify failed and HORIZON_MAIL empty.', [
                'summary' => $summary,
            ]);

            return;
        }

        Mail::to($mail)->send(new WebhookSecretVerifyFailedMail($summary));
    }

    /** @return list<string> */
    private function numbers(): array
    {
        $raw = config('services.ops.alert_whatsapp_numbers');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
```

- [ ] **Step 6: Run unit tests**

Run:

```bash
php artisan test tests/Unit/Services/Webhooks/WebhookSecretProbeTest.php tests/Unit/Services/Webhooks/WebhookRotationNotifierTest.php
```

Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/Webhooks app/Mail/WebhookSecretVerifyFailedMail.php resources/views/mail/webhook-secret-verify-failed.blade.php tests/Unit/Services/Webhooks
git commit -m "$(cat <<'EOF'
feat(webhooks): add secret probe and ops rotation notifier

EOF
)"
```

Note: `config/services.php` `ops` block was already added in Task 1 — do not duplicate.

---

### Task 5: Artisan command `webhooks:rotate-secret`

**Files:**
- Create: `app/Console/Commands/RotateWebhookSecretCommand.php`
- Create: `tests/Feature/Console/RotateWebhookSecretCommandTest.php`

**Interfaces:**
- Signature: `webhooks:rotate-secret {--write-env} {--cache-config} {--apply-green} {--clear-previous} {--dry-run}`
- Exit `self::SUCCESS` when verify OK (or dry-run / successful mutate+verify)
- Exit `self::FAILURE` when gates fail, mutate/Green fails, or verify fails
- Laravel 13 auto-discovers commands under `app/Console/Commands` (no manual registration)

- [ ] **Step 1: Write failing command tests**

Create `tests/Feature/Console/RotateWebhookSecretCommandTest.php`:

```php
<?php

use App\Mail\WebhookSecretVerifyFailedMail;
use App\Models\Integration\Instancia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config([
        'app.url' => 'https://api.test',
        'services.ops.probe_base_url' => null,
        'services.webhooks.secret' => 'cur',
        'services.webhooks.previous_secret' => '',
        'services.green_api.webhook_secret' => 'cur',
        'services.green_api.webhook_url' => 'https://api.test/api/v1/webhooks/incoming',
        'services.green_api.base_url' => 'https://api.green-api.com',
        'services.ops.alert_mail' => 'ops@example.com',
        'services.ops.alert_whatsapp_numbers' => '',
        'services.ops.alert_instance_public_id' => '',
        'services.ops.env_path' => null,
    ]);
});

test('rotate-secret verify success notifies without mail failure', function () {
    Mail::fake();
    Http::fake([
        'https://api.test/api/v1/webhooks/incoming' => Http::response(['received' => true, 'probe' => true], 200),
    ]);

    $this->artisan('webhooks:rotate-secret')
        ->assertSuccessful();

    Mail::assertNothingSent();
});

test('rotate-secret verify failure sends mail', function () {
    Mail::fake();
    Http::fake([
        'https://api.test/api/v1/webhooks/incoming' => Http::response(['message' => 'no'], 401),
    ]);

    $this->artisan('webhooks:rotate-secret')
        ->assertFailed();

    Mail::assertSent(WebhookSecretVerifyFailedMail::class);
});

test('dry-run skips probe and notifications', function () {
    Mail::fake();
    Http::fake();

    $this->artisan('webhooks:rotate-secret', ['--dry-run' => true])
        ->assertSuccessful();

    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('write-env and clear-previous together are rejected', function () {
    $this->artisan('webhooks:rotate-secret', [
        '--write-env' => true,
        '--clear-previous' => true,
    ])->assertFailed();
});

test('write-env moves current to previous and writes new secret', function () {
    Mail::fake();
    Http::fake([
        'https://api.test/api/v1/webhooks/incoming' => Http::response(['received' => true, 'probe' => true], 200),
    ]);

    $path = sys_get_temp_dir().'/rotate-env-'.uniqid('', true);
    file_put_contents($path, "WEBHOOK_SECRET=old-secret\n");
    config([
        'services.ops.env_path' => $path,
        'services.webhooks.secret' => 'old-secret',
        'services.green_api.webhook_secret' => 'old-secret',
    ]);

    $this->artisan('webhooks:rotate-secret', ['--write-env' => true])
        ->assertSuccessful();

    $contents = file_get_contents($path);
    expect($contents)->toContain('WEBHOOK_SECRET_PREVIOUS=old-secret')
        ->and($contents)->not->toContain('WEBHOOK_SECRET=old-secret')
        ->and($contents)->toMatch('/^WEBHOOK_SECRET=[0-9a-f]{64}$/m');

    unlink($path);
});

test('apply-green pushes webhookUrlToken to eligible instances', function () {
    Mail::fake();
    Http::fake([
        'https://api.test/api/v1/webhooks/incoming' => Http::response(['received' => true, 'probe' => true], 200),
        '*/waInstance1102000001/setSettings/*' => Http::response(['saveSettings' => true], 200),
    ]);

    Instancia::factory()->authorized()->create([
        'id_instance' => '1102000001',
        'api_token_instance' => 'token-test',
        'status' => 'authorized',
    ]);

    Instancia::factory()->create([
        'id_instance' => '1102999999',
        'api_token_instance' => 'token-deleted',
        'status' => 'deleted',
    ]);

    $this->artisan('webhooks:rotate-secret', ['--apply-green' => true])
        ->assertSuccessful();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/waInstance1102000001/setSettings/')) {
            return false;
        }
        $data = $request->data();

        return ($data['webhookUrlToken'] ?? null) === 'cur'
            && array_key_exists('webhookUrl', $data)
            && ($data['incomingWebhook'] ?? null) === 'yes'
            && ($data['stateWebhook'] ?? null) === 'yes';
    });

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/waInstance1102999999/'));
});
```

**Config-cache gate test** (add when easy to stub; otherwise document manual check):

If the test harness can force `configurationIsCached()` true, assert `--write-env` without `--cache-config` fails before touching the temp `.env`. If stubbing is awkward in Pest, implement the gate in the command and cover with a focused unit/feature test that binds a testable `requiresCacheConfig(): bool` protected method — preferred approach in the command:

```php
protected function mustCacheConfigAfterEnvMutation(): bool
{
    return app()->configurationIsCached();
}
```

In the test, create a subclass or use `PartialMock` — **simpler approach for Pest:** extract gate to a private check and test via artisan after writing a tiny `bootstrap/cache/config.php` is too heavy. Instead add this feature test pattern using Mockery alias is discouraged.

**Pragmatic test:** document gate in Global Constraints; add an integration-style test only if cheap. Minimum: command code must call `if ($this->option('write-env') || $this->option('clear-previous')) { if (app()->configurationIsCached() && ! $this->option('cache-config')) { $this->error(...); return FAILURE; } }` **before** any mutate. Optionally unit-test by temporarily creating `bootstrap/cache/config.php` in the test process and deleting it in `finally` — only if the Laravel app under test actually reports `configurationIsCached()` true when that file exists. Prefer:

```php
test('write-env without cache-config fails when config is cached', function () {
    $cachePath = base_path('bootstrap/cache/config.php');
    $created = false;
    if (! file_exists($cachePath)) {
        file_put_contents($cachePath, '<?php return [];');
        $created = true;
    }

    try {
        expect(app()->configurationIsCached())->toBeTrue();

        $path = sys_get_temp_dir().'/rotate-env-gate-'.uniqid('', true);
        file_put_contents($path, "WEBHOOK_SECRET=old\n");
        $before = file_get_contents($path);
        config(['services.ops.env_path' => $path]);

        $this->artisan('webhooks:rotate-secret', ['--write-env' => true])
            ->assertFailed();

        expect(file_get_contents($path))->toBe($before);
        unlink($path);
    } finally {
        if ($created && file_exists($cachePath)) {
            unlink($cachePath);
        }
    }
});
```

- [ ] **Step 2: Run command tests — expect FAIL**

Run: `php artisan test tests/Feature/Console/RotateWebhookSecretCommandTest.php`

Expected: FAIL (command missing)

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Exceptions\GreenApiException;
use App\Models\Integration\Instancia;
use App\Services\GreenApi\InstanceClient;
use App\Services\Webhooks\WebhookRotationNotifier;
use App\Services\Webhooks\WebhookSecretProbe;
use App\Support\EnvFileKeyWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RotateWebhookSecretCommand extends Command
{
    protected $signature = 'webhooks:rotate-secret
        {--write-env : Generate new secret; move current to PREVIOUS in .env}
        {--cache-config : Run config:cache after env mutation (required when config is cached)}
        {--apply-green : Push current webhookUrlToken to all Green instances}
        {--clear-previous : Clear WEBHOOK_SECRET_PREVIOUS in .env}
        {--dry-run : Log actions only; no mutate/probe/notify}';

    protected $description = 'Rotate or verify WEBHOOK_SECRET (dual-secret safe)';

    public function handle(WebhookSecretProbe $probe, WebhookRotationNotifier $notifier): int
    {
        if ($this->option('write-env') && $this->option('clear-previous')) {
            $this->error('Refuse: --write-env and --clear-previous together destroy the grace window.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry-run: no .env writes, Green calls, probe, or notifications.');
            if ($this->option('write-env')) {
                $this->line('Would write-env (generate secret, set PREVIOUS).');
            }
            if ($this->option('apply-green')) {
                $this->line('Would apply-green setSettings on instances.');
            }
            if ($this->option('clear-previous')) {
                $this->line('Would clear WEBHOOK_SECRET_PREVIOUS.');
            }
            if (($this->option('write-env') || $this->option('clear-previous'))
                && app()->configurationIsCached()
                && ! $this->option('cache-config')) {
                $this->line('Would also require --cache-config (config is cached).');
            }
            $this->line('Would probe + notify.');

            return self::SUCCESS;
        }

        if (($this->option('write-env') || $this->option('clear-previous'))
            && app()->configurationIsCached()
            && ! $this->option('cache-config')) {
            $this->error('Config is cached. Pass --cache-config with --write-env/--clear-previous so PHP-FPM sees dual secrets before probe/traffic.');

            return self::FAILURE;
        }

        try {
            if ($this->option('write-env')) {
                $this->writeEnv();
            }

            if ($this->option('clear-previous')) {
                $this->clearPrevious();
            }

            if ($this->option('cache-config') && ($this->option('write-env') || $this->option('clear-previous'))) {
                Artisan::call('config:cache');
                $this->info('config:cache completed.');
            }

            if ($this->option('apply-green')) {
                $this->applyGreen();
            }
        } catch (Throwable $e) {
            $this->error('Rotation mutate failed: '.$e->getMessage());
            $notifier->notifyFailure('mutate failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $result = $probe->probe();
        $summary = collect($result['results'])
            ->map(fn (array $r) => $r['label'].'='.($r['status'] ?? $r['error']))
            ->implode('; ');

        if ($result['ok']) {
            $this->info('Probe OK: '.$summary);
            $notifier->notifySuccess('webhook secret verify OK ('.$summary.')');

            return self::SUCCESS;
        }

        $this->error('Probe FAILED: '.$summary);
        $notifier->notifyFailure('probe failed: '.$summary);

        return self::FAILURE;
    }

    private function envPath(): string
    {
        $configured = config('services.ops.env_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : base_path('.env');
    }

    private function writeEnv(): void
    {
        $current = (string) config('services.webhooks.secret');
        $new = bin2hex(random_bytes(32));
        $writer = new EnvFileKeyWriter($this->envPath());
        if ($current !== '') {
            $writer->set('WEBHOOK_SECRET_PREVIOUS', $current);
        }
        $writer->set('WEBHOOK_SECRET', $new);
        // Keep process config in sync for subsequent apply-green/probe in same process:
        config([
            'services.webhooks.secret' => $new,
            'services.webhooks.previous_secret' => $current !== '' ? $current : config('services.webhooks.previous_secret'),
            'services.green_api.webhook_secret' => $new,
        ]);
        $this->info('Wrote WEBHOOK_SECRET (+ PREVIOUS). Secret value not printed.');
    }

    private function clearPrevious(): void
    {
        (new EnvFileKeyWriter($this->envPath()))->clear('WEBHOOK_SECRET_PREVIOUS');
        config(['services.webhooks.previous_secret' => '']);
        $this->info('Cleared WEBHOOK_SECRET_PREVIOUS.');
    }

    private function applyGreen(): void
    {
        $token = config('services.green_api.webhook_secret');
        $url = config('services.green_api.webhook_url');
        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('Current webhook secret empty; abort apply-green.');
        }
        if (! is_string($url) || $url === '') {
            throw new \RuntimeException('GREEN_API_WEBHOOK_URL / webhook_url empty; abort apply-green.');
        }

        $instances = Instancia::query()
            ->withoutGlobalScope('tenant')
            ->where('status', '!=', 'deleted')
            ->whereNotNull('id_instance')
            ->where('id_instance', '!=', '')
            ->whereNotNull('api_token_instance')
            ->where('api_token_instance', '!=', '')
            ->orderBy('id')
            ->get();

        $ok = 0;
        $fail = 0;
        foreach ($instances as $instancia) {
            try {
                $client = new InstanceClient(
                    (string) config('services.green_api.base_url'),
                    (string) $instancia->id_instance,
                    (string) $instancia->api_token_instance,
                );
                // Match ProvisionGreenInstanceJob; include delay if that job already ships it.
                $settings = [
                    'webhookUrl' => $url,
                    'webhookUrlToken' => $token,
                    'incomingWebhook' => 'yes',
                    'stateWebhook' => 'yes',
                ];
                // Forward-compat with green delay plan / provisioning:
                if ($this->provisioningIncludesSendDelay()) {
                    $settings['delaySendMessagesMilliseconds'] = 15000;
                }
                $client->setSettings($settings);
                $ok++;
            } catch (Throwable $e) {
                $fail++;
                $this->error('setSettings failed for instancia id='.$instancia->id.': '.$e->getMessage());
            }
        }

        $this->info("apply-green done: ok={$ok} fail={$fail}");
        if ($fail > 0) {
            throw new \RuntimeException('One or more Green setSettings calls failed.');
        }
    }

    /**
     * True when ProvisionGreenInstanceJob (or config) already sets send delay.
     * Avoid hard-coding forever: check source once at implement time — if the job
     * contains delaySendMessagesMilliseconds, return true; else false.
     */
    private function provisioningIncludesSendDelay(): bool
    {
        // Implementer: set to true iff ProvisionGreenInstanceJob setSettings includes
        // delaySendMessagesMilliseconds => 15000 at merge time. Do not invent a
        // second source of truth — mirror that job's payload.
        return false;
    }
}
```

**Implementer note on `provisioningIncludesSendDelay()`:** at merge time, open `ProvisionGreenInstanceJob`. If it already has `'delaySendMessagesMilliseconds' => 15000`, change the method to `return true;` (or inline the key in `$settings`). Do not leave a stale `false` after that job ships — regression risk for Yellow Card.

Catch `GreenApiException` is covered by `Throwable`. `api_token_instance` is encrypted cast; reading yields plaintext — never print it.

- [ ] **Step 4: Run command tests**

Run: `php artisan test tests/Feature/Console/RotateWebhookSecretCommandTest.php`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/RotateWebhookSecretCommand.php tests/Feature/Console/RotateWebhookSecretCommandTest.php
git commit -m "$(cat <<'EOF'
feat(webhooks): add webhooks:rotate-secret hybrid command

EOF
)"
```

---

### Task 6: Docs + spec link + full test suite

**Files:**
- Modify: `docs/DEPLOY.md`
- Modify: `docs/superpowers/specs/2026-07-23-webhook-secret-rotation-dual-secret-design.md` (plan link + estado + gates)
- Prefer a section in DEPLOY (no separate runbook unless DEPLOY grows unwieldy)

- [ ] **Step 1: Add DEPLOY section**

Append to `docs/DEPLOY.md` (use a fenced bash block inside the markdown section — do not nest ``` markdown fences incorrectly; write the section as normal markdown with one bash fence):

Content to append:

````
## Rotación de WEBHOOK_SECRET (sin downtime)

Dual secret: `WEBHOOK_SECRET` (actual) + `WEBHOOK_SECRET_PREVIOUS` (gracia).

Ops alerts: `HORIZON_MAIL`, `OPS_ALERT_WHATSAPP_NUMBERS`, `OPS_ALERT_INSTANCE_PUBLIC_ID`.

Prerequisitos OPS WhatsApp (verify OK → WA): instancia `OPS_ALERT_INSTANCE_PUBLIC_ID` en status `authorized`, módulo WhatsApp del tenant habilitado, cuota libre. `MessageSendService` revalida estado en Green.

### Runbook manual (orden seguro)

En producción `config` suele estar cacheado: **siempre** pasar `--cache-config` con mutaciones de `.env`.

```bash
cd /home/lebytek-api/htdocs/api.lebytek.com

# 1) Rotar .env + refrescar config (FPM acepta actual + previous)
sudo -u lebytek-api php artisan webhooks:rotate-secret --write-env --cache-config

# 2) Empujar token actual a Green (gracia cubre la ventana)
sudo -u lebytek-api php artisan webhooks:rotate-secret --apply-green

# 3) Probe + WA/mail
sudo -u lebytek-api php artisan webhooks:rotate-secret

# Tras ~7 días sin 401 por secret viejo:
sudo -u lebytek-api php artisan webhooks:rotate-secret --clear-previous --cache-config
```

No cambia `publicId` ni tokens Sanctum de clientes.

No combinar `--write-env` y `--clear-previous` en la misma invocación.

Si el probe falla con `request_failed`, confirmar que `APP_URL=https://api.lebytek.com` es reachable desde el propio VPS.

### Post-deploy gate (primera vez que sube el código)

```bash
# Vars presentes (valores redactados)
sudo -u lebytek-api grep -E '^(WEBHOOK_SECRET|WEBHOOK_SECRET_PREVIOUS|HORIZON_MAIL|OPS_ALERT_)' .env | sed 's/=.*/=***/'

# Verify smoke (no muta)
sudo -u lebytek-api php artisan webhooks:rotate-secret
```

Esperado: exit 0 y (si OPS configurado) mensaje WA; si falla auth del secret actual → mail a `HORIZON_MAIL`.

### Cron futuro (solo verify — no activar rotación automática aún)

```cron
0 5 * * 0 cd /home/lebytek-api/htdocs/api.lebytek.com && sudo -u lebytek-api php artisan webhooks:rotate-secret >> /dev/null 2>&1
```

Spec: `docs/superpowers/specs/2026-07-23-webhook-secret-rotation-dual-secret-design.md`
````

Update spec header + decisions table for config-cache gate and flag conflict (see Task 6 alignment in the spec file).

- [ ] **Step 2: Run full related suite**

```bash
php artisan test tests/Feature/Webhooks tests/Feature/Console/RotateWebhookSecretCommandTest.php tests/Unit/Support/EnvFileKeyWriterTest.php tests/Unit/Services/Webhooks
```

Expected: all PASS

- [ ] **Step 3: Commit**

```bash
git add docs/DEPLOY.md docs/superpowers/specs/2026-07-23-webhook-secret-rotation-dual-secret-design.md
git commit -m "$(cat <<'EOF'
docs: webhook secret rotation runbook and cron note

EOF
)"
```

---

## Deploy readiness checklist (before first prod rotate)

| Check | Why |
|-------|-----|
| Code on `main` + VPS pull + `config:cache` already done for the new release | Middleware dual-secret must be live **before** first `--write-env` |
| `WEBHOOK_SECRET_PREVIOUS=` empty initially | Grace unused until first rotate |
| `HORIZON_MAIL` set | Failure alerts |
| `OPS_ALERT_*` set if WA desired | Success alerts |
| `APP_URL=https://api.lebytek.com` reachable from VPS | Probe HTTP |
| Run verify-only once post-deploy | Confirms dual path + notifier wiring without mutating |
| Only then runbook steps 1→2→3 | Zero-downtime rotate |

## Spec coverage self-review

| Spec requirement | Task |
|------------------|------|
| Dual HMAC/Bearer previous | Task 1 |
| No HMAC→Bearer fallback | Task 1 |
| `previous_secret` config + `.env.example` | Task 1 |
| `services.ops` + OPS vars | Task 1 |
| Probe skip-persist `rotate-probe-` | Task 2 |
| `--write-env` / `--clear-previous` | Tasks 3, 5 |
| Atomic `.env` write | Task 3 |
| `--cache-config` + mandatory when cached | Task 5 |
| Refuse write-env + clear-previous | Task 5 |
| `--apply-green` setSettings current only; skip deleted | Task 5 |
| Default verify probe | Tasks 4, 5 |
| WA success / mail failure / `HORIZON_MAIL` | Task 4 |
| Sync failure mail (no ShouldQueue) | Task 4 |
| `--dry-run` | Task 5 |
| No scheduler registration | Task 6 (docs only) |
| DEPLOY runbook + post-deploy gate | Task 6 |
| Clients publicId untouched | Global constraint |

## Placeholder scan

No TBD/TODO left as implementer homework except the explicit `provisioningIncludesSendDelay()` mirror of `ProvisionGreenInstanceJob` (checked at merge time — concrete boolean).

## Type consistency

- `WebhookSecretProbe::probe(): array{ok, results}` used by command.
- `WebhookRotationNotifier::notifySuccess/notifyFailure(string)`.
- `EnvFileKeyWriter::set/clear` with atomic rename.
- Config keys: `services.webhooks.previous_secret`, `services.ops.alert_mail|alert_whatsapp_numbers|alert_instance_public_id|env_path|probe_base_url`.
