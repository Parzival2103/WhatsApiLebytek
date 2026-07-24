# Webhook Persistence — Closing the Black Hole Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist every valid Green API webhook event to `int_webhooks` before processing, make the database unique index the sole deduplication authority, process all event types asynchronously in a job, and add a scheduled watcher that alerts on unprocessed backlog — so no webhook event is ever silently discarded.

**Architecture:** The controller stops processing inline. It resolves an event id, inserts one `int_webhooks` row (catching the unique-index violation as the duplicate signal), dispatches `ProcessWebhookJob`, and acks `200`. The job resolves the tenant, branches by `type_webhook` (state changes apply domain logic; every other type is persist-only in v1), and stamps `processed_at`. A scheduled Artisan command reports rows left with `processed_at IS NULL` beyond a threshold.

**Tech Stack:** Laravel 11, PHP 8.3, Pest (feature + unit), Redis queue (`sync` in tests), Eloquent, existing `VerifyWebhookSignature` middleware (unchanged).

## Global Constraints

- **PHP:** `^8.3`. Use typed properties, typed constants (`private const int`), and constructor property promotion, matching existing code.
- **Tests:** Pest function style (`test('...', function () { ... })`). `QUEUE_CONNECTION=sync` in `phpunit.xml` — dispatched jobs run inline unless faked with `Queue::fake()`.
- **Table already exists:** `int_webhooks` (migration `2026_07_02_100001`). Columns: `id`, `event_id` (unique), `type_webhook`, `id_instance` (nullable), `payload` (json, not null), `processed_at` (nullable), `tenant_id` (nullable FK `core_tenants`), `created_at`, `updated_at`. **Do not create a new migration.**
- **Webhook secret config key:** `services.webhooks.secret`. Tests set `config(['services.webhooks.secret' => 'test-webhook-secret'])`.
- **Route name:** `api.v1.webhooks.incoming`. Webhook route group uses middleware `['webhook.signature', ...]` — `webhook.signature` (`VerifyWebhookSignature`) stays; `webhook.idempotency` is being retired.
- **Cross-tenant sink:** the `Webhook` model must **not** use the `BelongsToTenant` trait. Rows are inserted with `tenant_id = null` and resolved later in the job. Always query `Instancia` with `->withoutGlobalScope('tenant')` inside the job.
- **event_id storage:** store `sha1(<resolved semantic key>)` (fixed 40 chars) so the unique index length is bounded and stable, mirroring the retired middleware's `sha1($eventId)` precedent.

---

## File Structure

**Created:**
- `app/Models/Integration/Webhook.php` — Eloquent model over `int_webhooks` (no tenant scope).
- `database/factories/Integration/WebhookFactory.php` — factory for tests.
- `app/Support/WebhookEventId.php` — pure event-id resolver, extracted from the retired middleware.
- `app/Jobs/ProcessWebhookJob.php` — resolves tenant, branches by type, stamps `processed_at`.
- `app/Exceptions/WebhookInstanceNotReadyException.php` — thrown to trigger queue retry when a `stateInstanceChanged` references an instance that does not exist yet.
- `app/Console/Commands/CheckUnprocessedWebhooksCommand.php` — the backlog watcher.
- `tests/Unit/Support/WebhookEventIdTest.php` — resolver unit tests (preserves valuable dedup cases).
- `tests/Feature/Jobs/ProcessWebhookJobTest.php` — job behavior.
- `tests/Feature/Console/CheckUnprocessedWebhooksTest.php` — watcher behavior.

**Modified:**
- `app/Http/Controllers/Api/V1/IncomingWebhookController.php` — resolve → insert → dispatch → ack; no inline processing.
- `routes/api.php` — drop `webhook.idempotency` from the webhook group; remove the `PUT /credentials/green-api` route.
- `bootstrap/app.php` — remove the `webhook.idempotency` alias.
- `routes/console.php` — schedule `webhooks:check-unprocessed`.
- `config/permissions.php` — remove `credenciales.gestionar` from `nucleo` and `platform_service`.
- `docs/integration/waapi-api-contract.md` — drop the `PUT /credentials/green-api` row.
- `tests/Feature/Webhooks/WebhookGreenBearerTest.php` — rewritten to assert persistence (was dedup-verdict only).

**Deleted:**
- `app/Http/Middleware/WebhookIdempotency.php` — dedup authority moves to the DB.
- `app/Http/Controllers/Api/V1/CredentialsController.php` — 501 stub, never implemented.
- `tests/Feature/Api/CredentialsStubTest.php` — tests the removed stub.

---

## Task 1: Webhook model and factory

**Files:**
- Create: `app/Models/Integration/Webhook.php`
- Create: `database/factories/Integration/WebhookFactory.php`
- Test: `tests/Feature/Jobs/ProcessWebhookJobTest.php` (temporary smoke test here; real job tests land in Task 4)

**Interfaces:**
- Consumes: existing `int_webhooks` schema; `App\Models\Integration\Instancia`.
- Produces:
  - `App\Models\Integration\Webhook` — fillable `['event_id','type_webhook','id_instance','payload','processed_at','tenant_id']`; casts `payload => 'array'`, `processed_at => 'datetime'`; `$table = 'int_webhooks'`; **no** `BelongsToTenant`.
  - `Database\Factories\Integration\WebhookFactory` with default state and a `processed()` state helper.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Jobs/ProcessWebhookJobTest.php`:

```php
<?php

use App\Models\Integration\Webhook;

test('webhook model persists payload as array and casts processed_at', function () {
    $webhook = Webhook::factory()->create([
        'event_id' => 'evt-'.uniqid(),
        'type_webhook' => 'incomingMessageReceived',
        'id_instance' => '1101234567',
        'payload' => ['typeWebhook' => 'incomingMessageReceived', 'foo' => 'bar'],
    ]);

    $fresh = Webhook::query()->find($webhook->id);

    expect($fresh->payload)->toBe(['typeWebhook' => 'incomingMessageReceived', 'foo' => 'bar'])
        ->and($fresh->processed_at)->toBeNull()
        ->and($fresh->tenant_id)->toBeNull();
});

test('webhook factory processed state stamps processed_at', function () {
    $webhook = Webhook::factory()->processed()->create();

    expect($webhook->processed_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Jobs/ProcessWebhookJobTest.php`
Expected: FAIL — `Class "App\Models\Integration\Webhook" not found`.

- [ ] **Step 3: Create the model**

Create `app/Models/Integration/Webhook.php`:

```php
<?php

namespace App\Models\Integration;

use Database\Factories\Integration\WebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'event_id',
    'type_webhook',
    'id_instance',
    'payload',
    'processed_at',
    'tenant_id',
])]
class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use HasFactory;

    protected $table = 'int_webhooks';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 4: Create the factory**

Create `database/factories/Integration/WebhookFactory.php`:

```php
<?php

namespace Database\Factories\Integration;

use App\Models\Integration\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    protected $model = Webhook::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => sha1((string) Str::ulid()),
            'type_webhook' => 'incomingMessageReceived',
            'id_instance' => (string) fake()->numerify('110########'),
            'payload' => ['typeWebhook' => 'incomingMessageReceived'],
            'processed_at' => null,
            'tenant_id' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'processed_at' => now(),
        ]);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Jobs/ProcessWebhookJobTest.php`
Expected: PASS (2 passed).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Integration/Webhook.php database/factories/Integration/WebhookFactory.php tests/Feature/Jobs/ProcessWebhookJobTest.php
git commit -m "feat(webhooks): add Webhook model and factory over int_webhooks"
```

---

## Task 2: WebhookEventId resolver

Extract the retired middleware's `resolveEventId` logic into a pure, unit-testable helper. This is the shared source of truth for the semantic dedup key.

**Files:**
- Create: `app/Support/WebhookEventId.php`
- Test: `tests/Unit/Support/WebhookEventIdTest.php`

**Interfaces:**
- Consumes: `Illuminate\Http\Request`.
- Produces: `App\Support\WebhookEventId::resolve(Request $request): string` — returns the **semantic** key (not hashed). Cascade: `X-Event-Id` header → `idMessage` composite → `idWebhook` composite → `typeWebhook|idInstance|timestamp|…` composite → `sha1(body)`. Callers hash the result for storage.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/WebhookEventIdTest.php`:

```php
<?php

use App\Support\WebhookEventId;
use Illuminate\Http\Request;

function makeWebhookRequest(array $payload, array $headers = []): Request
{
    $body = json_encode($payload);
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Request::create('/api/v1/webhooks/incoming', 'POST', [], [], [], $server, $body);
}

test('prefers explicit X-Event-Id header when present', function () {
    $request = makeWebhookRequest(['typeWebhook' => 'stateInstanceChanged'], ['X-Event-Id' => '  evt-42  ']);

    expect(WebhookEventId::resolve($request))->toBe('evt-42');
});

test('status events sharing idMessage produce distinct keys per status', function () {
    $sent = makeWebhookRequest([
        'typeWebhook' => 'outgoingMessageStatus',
        'instanceData' => ['idInstance' => 1101234567],
        'idMessage' => 'green-msg-shared',
        'status' => 'sent',
    ]);
    $read = makeWebhookRequest([
        'typeWebhook' => 'outgoingMessageStatus',
        'instanceData' => ['idInstance' => 1101234567],
        'idMessage' => 'green-msg-shared',
        'status' => 'read',
    ]);

    expect(WebhookEventId::resolve($sent))->not->toBe(WebhookEventId::resolve($read));
});

test('same idMessage from different instances produces distinct keys', function () {
    $a = makeWebhookRequest([
        'typeWebhook' => 'incomingMessageReceived',
        'instanceData' => ['idInstance' => 1101111111],
        'idMessage' => 'green-msg-collision',
    ]);
    $b = makeWebhookRequest([
        'typeWebhook' => 'incomingMessageReceived',
        'instanceData' => ['idInstance' => 1102222222],
        'idMessage' => 'green-msg-collision',
    ]);

    expect(WebhookEventId::resolve($a))->not->toBe(WebhookEventId::resolve($b));
});

test('distinct state transitions in the same second produce distinct keys', function () {
    $notAuth = makeWebhookRequest([
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 1105554443],
        'stateInstance' => 'notAuthorized',
        'timestamp' => 1720000200,
    ]);
    $auth = makeWebhookRequest([
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 1105554443],
        'stateInstance' => 'authorized',
        'timestamp' => 1720000200,
    ]);

    expect(WebhookEventId::resolve($notAuth))->not->toBe(WebhookEventId::resolve($auth));
});

test('non scalar idMessage falls through to composite instead of colliding', function () {
    $a = makeWebhookRequest([
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 1107776665],
        'stateInstance' => 'authorized',
        'timestamp' => 1720000300,
        'idMessage' => [],
    ]);
    $b = makeWebhookRequest([
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 1107776665],
        'stateInstance' => 'authorized',
        'timestamp' => 1720000301,
        'idMessage' => [],
    ]);

    expect(WebhookEventId::resolve($a))->not->toBe(WebhookEventId::resolve($b));
});

test('payload without any ids falls back to body hash', function () {
    $request = makeWebhookRequest([
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => '1100000001'],
        'stateInstance' => 'notAuthorized',
    ]);

    expect(WebhookEventId::resolve($request))->toBe(sha1($request->getContent()));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Support/WebhookEventIdTest.php`
Expected: FAIL — `Class "App\Support\WebhookEventId" not found`.

- [ ] **Step 3: Create the helper**

Create `app/Support/WebhookEventId.php` (lifts the exact cascade from `WebhookIdempotency::resolveEventId`):

```php
<?php

namespace App\Support;

use Illuminate\Http\Request;

class WebhookEventId
{
    public static function resolve(Request $request): string
    {
        $header = $request->header('X-Event-Id');

        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        $payload = self::jsonBody($request);

        $typeWebhook = self::scalar($payload['typeWebhook'] ?? null);

        $instanceData = is_array($payload['instanceData'] ?? null) ? $payload['instanceData'] : [];
        $idInstance = self::scalar($instanceData['idInstance'] ?? $payload['idInstance'] ?? null);

        $idMessage = self::scalar($payload['idMessage'] ?? null);
        if ($idMessage !== '') {
            return self::composite([
                $typeWebhook,
                $idInstance,
                $idMessage,
                self::scalar($payload['status'] ?? null),
            ]);
        }

        $idWebhook = self::scalar($payload['idWebhook'] ?? null);
        if ($idWebhook !== '') {
            return self::composite([$typeWebhook, $idInstance, $idWebhook]);
        }

        $timestamp = self::scalar($payload['timestamp'] ?? null);

        if ($typeWebhook !== '' && $idInstance !== '' && $timestamp !== '') {
            $senderData = is_array($payload['senderData'] ?? null) ? $payload['senderData'] : [];

            return self::composite([
                $typeWebhook,
                $idInstance,
                $timestamp,
                self::scalar($payload['stateInstance'] ?? null),
                self::scalar($senderData['chatId'] ?? $payload['chatId'] ?? $payload['from'] ?? null),
                substr(sha1($request->getContent()), 0, 16),
            ]);
        }

        return sha1($request->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonBody(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  list<string>  $parts
     */
    private static function composite(array $parts): string
    {
        return implode('|', $parts);
    }

    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Support/WebhookEventIdTest.php`
Expected: PASS (6 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Support/WebhookEventId.php tests/Unit/Support/WebhookEventIdTest.php
git commit -m "feat(webhooks): extract WebhookEventId resolver as shared helper"
```

---

## Task 3: ProcessWebhookJob

The job is the single processing path for every event type. It resolves the tenant, applies domain logic only for `stateInstanceChanged` (persist-only for all other types in v1), and stamps `processed_at`. When a `stateInstanceChanged` references an instance that doesn't exist yet, it throws `WebhookInstanceNotReadyException` so the queue retries — resolving the `id_instance` provisioning race without any false error semantics.

**Files:**
- Create: `app/Exceptions/WebhookInstanceNotReadyException.php`
- Create: `app/Jobs/ProcessWebhookJob.php`
- Test: `tests/Feature/Jobs/ProcessWebhookJobTest.php` (append to the file from Task 1)

**Interfaces:**
- Consumes: `App\Models\Integration\Webhook`, `App\Models\Integration\Instancia`.
- Produces:
  - `App\Jobs\ProcessWebhookJob` — `public readonly int $webhookId`, constructor calls `$this->onQueue('webhooks')`, `public int $tries = 5`, `public function backoff(): array` returns `[10, 30, 60, 120]`, `public function handle(): void`.
  - `App\Exceptions\WebhookInstanceNotReadyException extends \RuntimeException`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Jobs/ProcessWebhookJobTest.php`:

```php
use App\Exceptions\WebhookInstanceNotReadyException;
use App\Jobs\ProcessWebhookJob;
use App\Models\Integration\Instancia;

test('stateInstanceChanged authorized marks instance authorized and stamps processed_at', function () {
    $instancia = Instancia::factory()->create([
        'id_instance' => '1101234567',
        'status' => 'waiting_qr',
        'green_state' => 'notAuthorized',
        'authorized_at' => null,
    ]);

    $webhook = Webhook::factory()->create([
        'type_webhook' => 'stateInstanceChanged',
        'id_instance' => '1101234567',
        'payload' => [
            'typeWebhook' => 'stateInstanceChanged',
            'instanceData' => ['idInstance' => 1101234567],
            'stateInstance' => 'authorized',
        ],
    ]);

    (new ProcessWebhookJob($webhook->id))->handle();

    $instancia->refresh();
    $webhook->refresh();

    expect($instancia->green_state)->toBe('authorized')
        ->and($instancia->status)->toBe('authorized')
        ->and($instancia->authorized_at)->not->toBeNull()
        ->and($webhook->processed_at)->not->toBeNull()
        ->and($webhook->tenant_id)->toBe($instancia->tenant_id);
});

test('stateInstanceChanged notAuthorized reverts an authorized instance to waiting_qr', function () {
    $instancia = Instancia::factory()->create([
        'id_instance' => '1105554443',
        'status' => 'authorized',
        'green_state' => 'authorized',
        'authorized_at' => now(),
    ]);

    $webhook = Webhook::factory()->create([
        'type_webhook' => 'stateInstanceChanged',
        'id_instance' => '1105554443',
        'payload' => [
            'typeWebhook' => 'stateInstanceChanged',
            'instanceData' => ['idInstance' => 1105554443],
            'stateInstance' => 'notAuthorized',
        ],
    ]);

    (new ProcessWebhookJob($webhook->id))->handle();

    $instancia->refresh();

    expect($instancia->green_state)->toBe('notAuthorized')
        ->and($instancia->status)->toBe('waiting_qr')
        ->and($instancia->authorized_at)->toBeNull();
});

test('non-state event types are persist-only and just stamp processed_at', function () {
    $webhook = Webhook::factory()->create([
        'type_webhook' => 'incomingMessageReceived',
        'id_instance' => '1109990001',
        'payload' => [
            'typeWebhook' => 'incomingMessageReceived',
            'instanceData' => ['idInstance' => 1109990001],
        ],
    ]);

    (new ProcessWebhookJob($webhook->id))->handle();

    expect($webhook->refresh()->processed_at)->not->toBeNull();
});

test('non-state event resolves tenant_id from a known instance', function () {
    $instancia = Instancia::factory()->create(['id_instance' => '1108880002']);

    $webhook = Webhook::factory()->create([
        'type_webhook' => 'incomingMessageReceived',
        'id_instance' => '1108880002',
        'payload' => ['typeWebhook' => 'incomingMessageReceived', 'instanceData' => ['idInstance' => 1108880002]],
    ]);

    (new ProcessWebhookJob($webhook->id))->handle();

    expect($webhook->refresh()->tenant_id)->toBe($instancia->tenant_id);
});

test('stateInstanceChanged for an unknown instance throws and leaves the row unprocessed', function () {
    $webhook = Webhook::factory()->create([
        'type_webhook' => 'stateInstanceChanged',
        'id_instance' => '1100000009',
        'payload' => [
            'typeWebhook' => 'stateInstanceChanged',
            'instanceData' => ['idInstance' => 1100000009],
            'stateInstance' => 'authorized',
        ],
    ]);

    expect(fn () => (new ProcessWebhookJob($webhook->id))->handle())
        ->toThrow(WebhookInstanceNotReadyException::class);

    expect($webhook->refresh()->processed_at)->toBeNull();
});

test('the provisioning race self-heals when the job retries after the instance exists', function () {
    $webhook = Webhook::factory()->create([
        'type_webhook' => 'stateInstanceChanged',
        'id_instance' => '1100000010',
        'payload' => [
            'typeWebhook' => 'stateInstanceChanged',
            'instanceData' => ['idInstance' => 1100000010],
            'stateInstance' => 'authorized',
        ],
    ]);

    // First attempt: instance not committed yet.
    expect(fn () => (new ProcessWebhookJob($webhook->id))->handle())
        ->toThrow(WebhookInstanceNotReadyException::class);

    // Instance now exists; retry succeeds.
    $instancia = Instancia::factory()->create([
        'id_instance' => '1100000010',
        'status' => 'waiting_qr',
        'green_state' => 'notAuthorized',
    ]);

    (new ProcessWebhookJob($webhook->id))->handle();

    expect($instancia->refresh()->status)->toBe('authorized')
        ->and($webhook->refresh()->processed_at)->not->toBeNull();
});

test('an already-processed webhook is a no-op on re-run', function () {
    $instancia = Instancia::factory()->create([
        'id_instance' => '1100000011',
        'status' => 'authorized',
        'green_state' => 'authorized',
        'authorized_at' => now(),
    ]);

    $processedAt = now()->subHour();
    $webhook = Webhook::factory()->create([
        'type_webhook' => 'stateInstanceChanged',
        'id_instance' => '1100000011',
        'processed_at' => $processedAt,
        'payload' => [
            'typeWebhook' => 'stateInstanceChanged',
            'instanceData' => ['idInstance' => 1100000011],
            'stateInstance' => 'notAuthorized',
        ],
    ]);

    (new ProcessWebhookJob($webhook->id))->handle();

    // Instance untouched (still authorized) because the row was already processed.
    expect($instancia->refresh()->status)->toBe('authorized')
        ->and($webhook->refresh()->processed_at->equalTo($processedAt))->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Jobs/ProcessWebhookJobTest.php`
Expected: FAIL — `Class "App\Jobs\ProcessWebhookJob" not found`.

- [ ] **Step 3: Create the exception**

Create `app/Exceptions/WebhookInstanceNotReadyException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class WebhookInstanceNotReadyException extends RuntimeException
{
}
```

- [ ] **Step 4: Create the job**

Create `app/Jobs/ProcessWebhookJob.php`:

```php
<?php

namespace App\Jobs;

use App\Exceptions\WebhookInstanceNotReadyException;
use App\Models\Integration\Instancia;
use App\Models\Integration\Webhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWebhookJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $webhookId,
    ) {
        $this->onQueue('webhooks');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(): void
    {
        $webhook = Webhook::query()->find($this->webhookId);

        if ($webhook === null || $webhook->processed_at !== null) {
            return;
        }

        $idInstance = $webhook->id_instance;

        $instancia = $idInstance !== null && $idInstance !== ''
            ? Instancia::query()
                ->withoutGlobalScope('tenant')
                ->where('id_instance', $idInstance)
                ->first()
            : null;

        if ($instancia !== null && $webhook->tenant_id === null) {
            $webhook->tenant_id = $instancia->tenant_id;
            $webhook->save();
        }

        if ($webhook->type_webhook === 'stateInstanceChanged') {
            $this->handleStateInstanceChanged($webhook, $instancia);
        }

        // All other types are persist-only in v1: the row already captured the
        // full payload, so there is nothing left to translate into domain state.

        $webhook->forceFill(['processed_at' => now()])->save();
    }

    private function handleStateInstanceChanged(Webhook $webhook, ?Instancia $instancia): void
    {
        $payload = $webhook->payload;
        $state = (string) ($payload['stateInstance'] ?? '');

        if ($state === '') {
            return;
        }

        if ($instancia === null) {
            throw new WebhookInstanceNotReadyException(
                "Instance [{$webhook->id_instance}] not found for stateInstanceChanged webhook [{$webhook->id}]."
            );
        }

        $attributes = ['green_state' => $state];

        if ($state === 'authorized') {
            $attributes['status'] = 'authorized';
            $attributes['authorized_at'] = now();
        } elseif ($state === 'notAuthorized' && $instancia->status === 'authorized') {
            $attributes['status'] = 'waiting_qr';
            $attributes['authorized_at'] = null;
        }

        $instancia->update($attributes);
    }
}
```

Note: the `throw` happens **before** `processed_at` is stamped, so a failed attempt leaves the row unprocessed for the watcher. `$tries = 5` with `backoff()` gives the provisioning race time to resolve on a real Redis queue; after exhaustion the row stays unprocessed (and the job lands in `failed_jobs`), both of which the watcher and Horizon surface.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Jobs/ProcessWebhookJobTest.php`
Expected: PASS (9 passed — 2 from Task 1 + 7 here).

- [ ] **Step 6: Commit**

```bash
git add app/Exceptions/WebhookInstanceNotReadyException.php app/Jobs/ProcessWebhookJob.php tests/Feature/Jobs/ProcessWebhookJobTest.php
git commit -m "feat(webhooks): add ProcessWebhookJob with state logic and provisioning-race retry"
```

---

## Task 4: Rewire the controller and retire the idempotency middleware

The controller becomes: resolve event id → insert one row (unique violation = duplicate) → dispatch job → ack. Deduplication authority moves entirely to the `int_webhooks.event_id` unique index. The Redis-cache middleware is retired (DB is the sole source of truth, and keeping a second dedup path would mask the exact black-hole bug we are closing).

**Files:**
- Modify: `app/Http/Controllers/Api/V1/IncomingWebhookController.php`
- Modify: `routes/api.php:100-102` (webhook group middleware)
- Modify: `bootstrap/app.php:34` (remove alias)
- Delete: `app/Http/Middleware/WebhookIdempotency.php`
- Modify (rewrite): `tests/Feature/Webhooks/WebhookGreenBearerTest.php`

**Interfaces:**
- Consumes: `App\Support\WebhookEventId`, `App\Models\Integration\Webhook`, `App\Jobs\ProcessWebhookJob`.
- Produces: `IncomingWebhookController::__invoke(Request): JsonResponse` returning `200 {received:true, duplicate:false}` on persist, `200 {received:true, duplicate:true}` on a unique-index collision.

- [ ] **Step 1: Rewrite the feature test suite**

Replace the entire contents of `tests/Feature/Webhooks/WebhookGreenBearerTest.php` with:

```php
<?php

use App\Jobs\ProcessWebhookJob;
use App\Models\Integration\Webhook;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.webhooks.secret' => 'test-webhook-secret']);
    Cache::flush();
});

function postWebhook(array $payload): \Illuminate\Testing\TestResponse
{
    return test()->call(
        'POST',
        route('api.v1.webhooks.incoming'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-webhook-secret',
        ],
        json_encode($payload),
    );
}

test('every webhook type persists exactly one int_webhooks row and dispatches the job', function () {
    Queue::fake();

    $types = [
        ['typeWebhook' => 'incomingMessageReceived', 'instanceData' => ['idInstance' => 1101234567], 'idMessage' => 'm-1'],
        ['typeWebhook' => 'outgoingMessageStatus', 'instanceData' => ['idInstance' => 1101234567], 'idMessage' => 'm-2', 'status' => 'delivered'],
        ['typeWebhook' => 'outgoingAPIMessageReceived', 'instanceData' => ['idInstance' => 1101234567], 'idMessage' => 'm-3'],
        ['typeWebhook' => 'incomingCall', 'instanceData' => ['idInstance' => 1101234567], 'timestamp' => 1720000500, 'from' => '5215550001@c.us', 'status' => 'offer'],
        ['typeWebhook' => 'stateInstanceChanged', 'instanceData' => ['idInstance' => 1101234567], 'stateInstance' => 'authorized', 'timestamp' => 1720000600],
    ];

    foreach ($types as $payload) {
        postWebhook($payload)->assertOk()->assertJson(['received' => true, 'duplicate' => false]);
    }

    expect(Webhook::query()->count())->toBe(5);
    Queue::assertPushed(ProcessWebhookJob::class, 5);
});

test('duplicate delivery does not create a second row and returns duplicate true even with a cold cache', function () {
    Queue::fake();

    $payload = [
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 1109998887],
        'stateInstance' => 'authorized',
        'timestamp' => 1720000001,
    ];

    postWebhook($payload)->assertOk()->assertJson(['duplicate' => false]);

    // The DB — not Redis — is the dedup authority: flush the cache before the retry.
    Cache::flush();

    postWebhook($payload)->assertOk()->assertJson(['received' => true, 'duplicate' => true]);

    expect(Webhook::query()->count())->toBe(1);
    Queue::assertPushed(ProcessWebhookJob::class, 1);
});

test('stateInstanceChanged authorized ends with the instance authorized and the row processed', function () {
    // Sync queue (default in tests): the dispatched job runs inline, no Queue::fake().
    $instancia = \App\Models\Integration\Instancia::factory()->create([
        'id_instance' => '1101234567',
        'status' => 'waiting_qr',
        'green_state' => 'notAuthorized',
        'authorized_at' => null,
    ]);

    postWebhook([
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => 1101234567],
        'stateInstance' => 'authorized',
        'timestamp' => 1720000000,
    ])->assertOk()->assertJson(['received' => true, 'duplicate' => false]);

    $instancia->refresh();
    $webhook = Webhook::query()->firstOrFail();

    expect($instancia->status)->toBe('authorized')
        ->and($instancia->green_state)->toBe('authorized')
        ->and($instancia->authorized_at)->not->toBeNull()
        ->and($webhook->processed_at)->not->toBeNull();
});

test('same idMessage from different instances is not treated as a duplicate', function () {
    Queue::fake();

    $payload = fn (int $idInstance) => [
        'typeWebhook' => 'incomingMessageReceived',
        'instanceData' => ['idInstance' => $idInstance],
        'timestamp' => 1720000400,
        'idMessage' => 'green-msg-collision',
    ];

    postWebhook($payload(1101111111))->assertOk()->assertJson(['duplicate' => false]);
    postWebhook($payload(1102222222))->assertOk()->assertJson(['duplicate' => false]);
    postWebhook($payload(1102222222))->assertOk()->assertJson(['duplicate' => true]);

    expect(Webhook::query()->count())->toBe(2);
});

test('webhook without any ids still persists via the body-hash key', function () {
    Queue::fake();

    postWebhook([
        'typeWebhook' => 'stateInstanceChanged',
        'instanceData' => ['idInstance' => '1100000001'],
        'stateInstance' => 'notAuthorized',
    ])->assertOk()->assertJson(['received' => true, 'duplicate' => false]);

    expect(Webhook::query()->count())->toBe(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Webhooks/WebhookGreenBearerTest.php`
Expected: FAIL — no rows are persisted yet (`ProcessWebhookJob` not dispatched, `Webhook::count()` is 0). This confirms the tests exercise the new behavior.

- [ ] **Step 3: Rewrite the controller**

Replace the entire contents of `app/Http/Controllers/Api/V1/IncomingWebhookController.php` with:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookJob;
use App\Models\Integration\Webhook;
use App\Support\WebhookEventId;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Webhooks
 *
 * Incoming webhook endpoint (HMAC signature or Bearer token).
 *
 * Every valid event is persisted to int_webhooks before processing. The
 * event_id unique index is the deduplication authority; a collision returns
 * 200 {duplicate:true}. Processing happens asynchronously in ProcessWebhookJob.
 */
class IncomingWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();
        $eventId = sha1(WebhookEventId::resolve($request));

        $instanceData = is_array($payload['instanceData'] ?? null) ? $payload['instanceData'] : [];
        $idInstance = $instanceData['idInstance'] ?? $payload['idInstance'] ?? null;

        try {
            $webhook = Webhook::query()->create([
                'event_id' => $eventId,
                'type_webhook' => (string) ($payload['typeWebhook'] ?? ''),
                'id_instance' => $idInstance !== null ? (string) $idInstance : null,
                'payload' => $payload,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return response()->json(['received' => true, 'duplicate' => true]);
            }

            throw $e;
        }

        ProcessWebhookJob::dispatch($webhook->id);

        return response()->json(['received' => true, 'duplicate' => false]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }
}
```

Note: a non-unique `QueryException` (e.g. DB down) is re-thrown → `5xx`, so Green retries a delivery we did **not** persist. That is the one case where we want Green's retry.

- [ ] **Step 4: Retire the middleware from the route group**

In `routes/api.php`, change the webhook group (line ~100) from:

```php
Route::prefix('v1/webhooks')->middleware(['webhook.signature', 'webhook.idempotency'])->group(function (): void {
```

to:

```php
Route::prefix('v1/webhooks')->middleware(['webhook.signature'])->group(function (): void {
```

- [ ] **Step 5: Remove the middleware alias**

In `bootstrap/app.php`, delete this line from the `$middleware->alias([...])` array:

```php
            'webhook.idempotency' => \App\Http\Middleware\WebhookIdempotency::class,
```

- [ ] **Step 6: Delete the middleware class**

```bash
git rm app/Http/Middleware/WebhookIdempotency.php
```

- [ ] **Step 7: Run the webhook tests to verify they pass**

Run: `php artisan test tests/Feature/Webhooks/WebhookGreenBearerTest.php`
Expected: PASS (6 passed).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/V1/IncomingWebhookController.php routes/api.php bootstrap/app.php tests/Feature/Webhooks/WebhookGreenBearerTest.php
git commit -m "feat(webhooks): persist-then-dispatch controller; retire cache-only idempotency middleware"
```

---

## Task 5: Unprocessed-backlog watcher command and schedule

A scheduled Artisan command counts `int_webhooks` rows still unprocessed past a threshold. It is simultaneously the processing-failure detector and the event-loss alarm — the exact hole this whole plan closes.

**Files:**
- Create: `app/Console/Commands/CheckUnprocessedWebhooksCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/CheckUnprocessedWebhooksTest.php`

**Interfaces:**
- Consumes: `App\Models\Integration\Webhook`.
- Produces: Artisan command `webhooks:check-unprocessed {--minutes=5}` — exits `1` (FAILURE) and logs a warning when ≥1 unprocessed row is older than the threshold; exits `0` (SUCCESS) otherwise.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/CheckUnprocessedWebhooksTest.php`:

```php
<?php

use App\Models\Integration\Webhook;

test('reports stale unprocessed rows and exits non-zero', function () {
    Webhook::factory()->create([
        'event_id' => sha1('old-unprocessed'),
        'processed_at' => null,
        'created_at' => now()->subMinutes(10),
    ]);

    $this->artisan('webhooks:check-unprocessed')
        ->expectsOutputToContain('1')
        ->assertExitCode(1);
});

test('ignores processed rows and recent unprocessed rows', function () {
    Webhook::factory()->processed()->create([
        'event_id' => sha1('processed'),
        'created_at' => now()->subMinutes(30),
    ]);

    Webhook::factory()->create([
        'event_id' => sha1('recent-unprocessed'),
        'processed_at' => null,
        'created_at' => now()->subMinute(),
    ]);

    $this->artisan('webhooks:check-unprocessed')
        ->assertExitCode(0);
});

test('honors a custom minutes threshold', function () {
    Webhook::factory()->create([
        'event_id' => sha1('three-min-old'),
        'processed_at' => null,
        'created_at' => now()->subMinutes(3),
    ]);

    // Default 5-minute threshold: not stale yet.
    $this->artisan('webhooks:check-unprocessed')->assertExitCode(0);

    // 2-minute threshold: now stale.
    $this->artisan('webhooks:check-unprocessed', ['--minutes' => 2])->assertExitCode(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Console/CheckUnprocessedWebhooksTest.php`
Expected: FAIL — `Command "webhooks:check-unprocessed" is not defined`.

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/CheckUnprocessedWebhooksCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Integration\Webhook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckUnprocessedWebhooksCommand extends Command
{
    protected $signature = 'webhooks:check-unprocessed {--minutes=5 : Age threshold in minutes}';

    protected $description = 'Alert when int_webhooks rows remain unprocessed past a threshold';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $threshold = now()->subMinutes($minutes);

        $count = Webhook::query()
            ->whereNull('processed_at')
            ->where('created_at', '<', $threshold)
            ->count();

        if ($count === 0) {
            $this->info("No unprocessed webhooks older than {$minutes}m.");

            return self::SUCCESS;
        }

        $message = "{$count} unprocessed webhook(s) older than {$minutes}m.";

        Log::warning('Unprocessed webhook backlog detected.', [
            'count' => $count,
            'minutes' => $minutes,
        ]);

        $this->warn($message);

        return self::FAILURE;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Console/CheckUnprocessedWebhooksTest.php`
Expected: PASS (3 passed).

- [ ] **Step 5: Schedule the command**

Replace the contents of `routes/console.php` with:

```php
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('webhooks:check-unprocessed')->everyFiveMinutes();
```

- [ ] **Step 6: Verify the schedule is registered**

Run: `php artisan schedule:list`
Expected: output includes `webhooks:check-unprocessed` running `everyFiveMinutes` (`*/5 * * * *`).

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/CheckUnprocessedWebhooksCommand.php routes/console.php tests/Feature/Console/CheckUnprocessedWebhooksTest.php
git commit -m "feat(webhooks): add scheduled unprocessed-backlog watcher"
```

---

## Task 6: Cleanup — remove the `PUT /credentials/green-api` stub

Independent housekeeping bundled here so it is not lost: the 501 stub was bootstrap scaffolding, tenants never need to see Green credentials (partner-provisioned), and the `credenciales.gestionar` permission exists only to gate it.

**Files:**
- Modify: `routes/api.php` (remove the credentials route)
- Delete: `app/Http/Controllers/Api/V1/CredentialsController.php`
- Modify: `config/permissions.php` (remove `credenciales.gestionar` from `nucleo` and `platform_service`)
- Delete: `tests/Feature/Api/CredentialsStubTest.php`
- Modify: `docs/integration/waapi-api-contract.md:516` (drop the row)

**Interfaces:**
- Consumes: nothing new.
- Produces: no route named `api.v1.credentials.green-api`; no permission `credenciales.gestionar`.

- [ ] **Step 1: Delete the stub test**

```bash
git rm tests/Feature/Api/CredentialsStubTest.php
```

- [ ] **Step 2: Remove the route and its import**

In `routes/api.php`, delete the route block (lines ~95-97):

```php
    Route::put('/credentials/green-api', [CredentialsController::class, 'updateGreenApi'])
        ->middleware('permission:credenciales.gestionar')
        ->name('api.v1.credentials.green-api');
```

and remove the now-unused import at the top of the file:

```php
use App\Http\Controllers\Api\V1\CredentialsController;
```

- [ ] **Step 3: Delete the controller**

```bash
git rm app/Http/Controllers/Api/V1/CredentialsController.php
```

- [ ] **Step 4: Remove the permission from config**

In `config/permissions.php`, delete `'credenciales.gestionar',` from **both** the `nucleo` array (line ~25) and the `platform_service` array (line ~44).

- [ ] **Step 5: Update the API contract doc**

In `docs/integration/waapi-api-contract.md`, delete the table row (line ~516):

```
| PUT | `/credentials/green-api` | `credenciales.gestionar` | Credenciales cifradas por tenant |
```

- [ ] **Step 6: Verify no dangling references remain**

Run: `grep -rn "credenciales.gestionar\|CredentialsController\|credentials.green-api" app/ routes/ config/ tests/`
Expected: no matches (docs/specs may still reference it historically — that is fine).

- [ ] **Step 7: Run the full suite**

Run: `composer test`
Expected: PASS — all tests green, no reference to the removed route/permission/controller.

- [ ] **Step 8: Commit**

```bash
git add routes/api.php config/permissions.php docs/integration/waapi-api-contract.md
git commit -m "chore(api): remove unimplemented PUT /credentials/green-api stub and permission"
```

---

## Final verification

- [ ] **Run the full test suite**

Run: `composer test`
Expected: All tests pass, including the new webhook persistence, job, watcher, and event-id resolver tests.

- [ ] **Confirm the schedule**

Run: `php artisan schedule:list`
Expected: `webhooks:check-unprocessed` present at `*/5 * * * *`.

- [ ] **Confirm no orphaned references to retired components**

Run: `grep -rn "WebhookIdempotency\|webhook.idempotency" app/ routes/ bootstrap/ tests/`
Expected: no matches.

---

## Self-Review Notes

Spec coverage check against `docs/superpowers/specs/2026-07-17-webhook-persistence-black-hole-design.md`:

- **Persist every valid event before processing** → Task 4 controller insert; Task 4 test "every webhook type persists exactly one row".
- **Dedup authority = DB unique `event_id`, Redis demoted** → Task 4 controller catches `23000`; the "cold cache" test proves the DB is authority; the middleware (cache authority) is retired. The spec allows keeping an optional Redis fast-path; this plan drops it (YAGNI) so there is a single dedup path — the exact ambiguity the spec left open ("modificar o retirar").
- **Uniform async processing incl. `stateInstanceChanged`** → Task 3 `ProcessWebhookJob` handles all types; Task 4 dispatches for every event.
- **Health watcher (scheduled)** → Task 5 command + `routes/console.php` schedule.
- **Remove `PUT /credentials/green-api` + permission** → Task 6.
- **ack-on-persist** → Task 4 controller returns 200 right after the insert; job outcome does not affect the HTTP response (async in prod; job-level tests cover processing).
- **Error handling** — unique violation → 200 duplicate (Task 4); other DB error → re-thrown 5xx (Task 4); job failure → row stays unprocessed for the watcher (Task 3 + Task 5); instance-not-found race → `WebhookInstanceNotReadyException` retry, self-heals (Task 3).
- **Testing: persistence assertions, cold-cache dedup, race self-heal, watcher, preserved resolveEventId cases** → Tasks 2–5 cover each explicitly.
- **Out of scope (delivery receipts, inbound-as-domain, new business types)** → deliberately persist-only in Task 3; the plan adds no domain translation for non-state types.
```
