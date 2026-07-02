# Integration Roadmap Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align documented integration roadmap with reality by labeling Phase 2b (demo lifecycle) as done, implementing Phase 2a (`POST/GET /messages` MVP), completing Phase 3 go-live prerequisites, and gating Phase 4/5 until acceptance criteria §6 of the remediation spec pass.

**Architecture:** Enfoque B from remediation spec — docs truth first (R0), then minimal transactional WhatsApp vertical in `WhatsApiLebytek` (R1), Framework token abilities update (R1.7–R1.8), VPS ops/crons (R2), DNS/docs/main cutover only after message smoke green (R3). Campaigns, inbound webhooks processing, and `PUT /credentials/green-api` stay **planned** (YAGNI).

**Tech Stack:** Laravel 11+ (WhatsApiLebytek), Pest, Horizon/Redis, Green API `InstanceClient`, Sanctum RBAC, Lebytek Framework (Onion PHP), Docsify hub (`docs.lebytek.com`), VPS CloudPanel.

**Spec:** `docs/superpowers/specs/2026-07-02-integration-roadmap-remediation-design.md`

## Global Constraints

- **Enfoque B:** complete 2a + 3 before claiming “Fase 2/3 done”; preserve **Fase 2b** (deprovision/expire) as completed sub-phase.
- **YAGNI R1:** transactional `/messages` only — **no** `/campaigns`, **no** `PUT /credentials/green-api`, **no** inbound webhook processing (table `int_webhooks` created for audit; controller unchanged until post-remediation).
- **Repo source of truth:** `WhatsApiLebytek` for api + integration docs; `Lebytek_Framework` for back-office consumer.
- **Token demo abilities after R1.7:** `['instancias.ver', 'mensajes.enviar', 'mensajes.ver']`.
- **Idempotency-Key** required on `POST /messages`; same key + tenant → `200` with existing message.
- **Never expose** `apiTokenInstance` or Green tokens in JSON responses.
- **CI tests:** `Http::fake` / `Bus::fake` — no real Green API calls.
- **Gate §6:** do **not** start [2026-07-02-integration-phase4-5-design.md](../specs/2026-07-02-integration-phase4-5-design.md) until remediation acceptance criteria pass.
- **No** commitear `.env`, tokens, ni secretos.

---

## File map (before coding)

| File | Action | Responsibility |
|------|--------|----------------|
| `docs/integration/README.md` | Modify | Roadmap truth table 0/1, 2b, 2a, 3, 4/5 |
| `docs/superpowers/specs/2026-07-01-integration-phase2-3-design.md` | Modify | Banner: 2b done, 2a/3 pending |
| `docs/integration/VPS_CHECKLIST.md` | Modify | Honest checks; message smoke section; cron capture |
| `docs/superpowers/**` | Add to git | Untracked specs/plans |
| `database/migrations/2026_07_02_100000_create_int_mensajes_table.php` | **Create** | Outbound/inbound message rows |
| `database/migrations/2026_07_02_100001_create_int_webhooks_table.php` | **Create** | Webhook audit (no processor in R1) |
| `app/Models/Integration/Mensaje.php` | **Create** | Eloquent model |
| `database/factories/Integration/MensajeFactory.php` | **Create** | Test factory |
| `config/permissions.php` | Modify | `mensajes.enviar`, `mensajes.ver` |
| `app/Services/GreenApi/InstanceClient.php` | Modify | `sendMessage()` |
| `app/Services/Messaging/MessageSendService.php` | **Create** | Queue outbound, idempotency |
| `app/Http/Requests/Api/V1/StoreMessageRequest.php` | **Create** | Validation |
| `app/Http/Resources/Api/V1/MessageResource.php` | **Create** | camelCase JSON |
| `app/Http/Controllers/Api/V1/MessageController.php` | **Create** | store/show |
| `app/Jobs/TransactionalMessageJob.php` | Modify | Real Green send |
| `routes/api.php` | Modify | `/messages` routes |
| `tests/Unit/GreenApi/InstanceClientSendMessageTest.php` | **Create** | Http::fake sendMessage |
| `tests/Unit/Queue/TransactionalMessageJobTest.php` | **Create** | Job updates status |
| `tests/Feature/Api/MessageSendTest.php` | **Create** | POST/GET, RBAC, idempotency |
| `tests/Feature/Rbac/RbacSeederTest.php` | Modify | Assert mensajes permissions exist |
| `tests/Unit/Queue/HorizonQueueConfigTest.php` | Modify | New job constructor signature |
| `docs/integration/waapi-api-contract.md` | Modify | `/messages` → implementados |
| `Lebytek_Framework/app/Application/Marketing/LeadApiProvisioningService.php` | Modify | Demo token abilities |
| `Lebytek_Framework/scripts/resend-lead-credentials.php` | Modify | Same abilities |
| `Lebytek_Framework/tests/Integration/LeadApiProvisioningServiceTest.php` | Modify | Assert abilities in API body |
| `Lebytek_Framework/scripts/smoke-send-test-message.php` | **Create** | Optional manual smoke |
| `docs.lebytek.com/scripts/sync-docs.mjs` | Run (R3) | Sync hub from sibling repos |

---

### Task 1: R0 — Roadmap truth in integration README

**Files:**
- Modify: `docs/integration/README.md`

**Interfaces:**
- Consumes: remediation spec §5 R0 canonical table
- Produces: documented phase states for all downstream tasks

- [ ] **Step 1: Add roadmap section after the file table**

Append to `docs/integration/README.md`:

```markdown
## Roadmap real (integración)

| Fase | Nombre | Estado |
|------|--------|--------|
| 0/1 | E2E + back-office | ✅ |
| 2b | Lifecycle demo (baja/expiración) | ✅ |
| 2a | Vertical api `/messages` | ⏳ remediación |
| 3 | Go-live DNS/docs/main | ⏳ tras 2a |
| 4/5 | waapi + madurez | 📋 spec listo |

Spec remediación: [../superpowers/specs/2026-07-02-integration-roadmap-remediation-design.md](../superpowers/specs/2026-07-02-integration-roadmap-remediation-design.md)

> **No avanzar a Fase 4/5** hasta cumplir criterios §6 del spec de remediación.
```

- [ ] **Step 2: Verify locally**

Run: `grep -n "Roadmap real" docs/integration/README.md`

Expected: line match with table header.

- [ ] **Step 3: Commit**

```bash
git add docs/integration/README.md
git commit -m "docs: add honest integration roadmap with 2a/2b split"
```

---

### Task 2: R0 — Banner on original Phase 2/3 spec

**Files:**
- Modify: `docs/superpowers/specs/2026-07-01-integration-phase2-3-design.md:1-14`

**Interfaces:**
- Consumes: Task 1 roadmap naming
- Produces: readers see 2b vs 2a distinction before original scope

- [ ] **Step 1: Insert banner after title block**

After line 7 (`**Precede a:** ...`), insert:

```markdown

> **⚠️ Estado remediación (2026-07-02):** El trabajo **Fase 2b** (lifecycle demo: deprovision, expiración, estados CRUD) está **implementado** en Framework. Los entregables **Fase 2a** (vertical `/messages` en api) y **Fase 3** (go-live DNS/docs/main) siguen **pendientes**. Ver [2026-07-02-integration-roadmap-remediation-design.md](2026-07-02-integration-roadmap-remediation-design.md).
```

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-07-01-integration-phase2-3-design.md
git commit -m "docs: note 2b done and 2a/3 pending on phase 2/3 spec"
```

---

### Task 3: R0 — Honest VPS checklist

**Files:**
- Modify: `docs/integration/VPS_CHECKLIST.md`

**Interfaces:**
- Consumes: remediation spec §6.3 smoke steps
- Produces: checklist blocks DNS/message smoke until R1/R3

- [ ] **Step 1: Add remediation section after E2E Fase 0 block**

After line 19 (`Flujo E2E manual: ...`), insert:

```markdown

---

## Remediación — Fase 2a smoke mensaje (bloqueado hasta R1)

**No marcar `[x]` hasta smoke manual documentado con fecha.**

| Paso | Check | Estado |
|------|-------|--------|
| 1 | Lead `validada` → Provisionar demo → `demo_enviada` + correo | [x] 2026-07-01 |
| 2 | Cliente autoriza WhatsApp (QR vía api) | [ ] |
| 3 | `POST /messages` con token del correo → WhatsApp recibido en móvil | [ ] |
| 4 | Dar de baja demo → instancias eliminadas, `demo_baja` | [ ] |
| 5 | `docs.lebytek.com` muestra `/messages` implementado | [ ] |

## Remediación — Crons (R2)

```bash
# Capturar salida en este checklist al confirmar:
crontab -l -u lebytek
```

- [ ] Cron health cada 5 min: `php /home/lebytek/htdocs/lebytek.com/scripts/lebytek-api-health.php`
- [ ] Cron expire demos diario 03:00: `php /home/lebytek/htdocs/lebytek.com/scripts/expire-api-demos.php`

## Remediación — Go-live Fase 3 (bloqueado hasta R1 smoke verde)

- [ ] **Do not** DNS cutover until paso 3 smoke mensaje verde
- [ ] Framework `feature/backoffice-api-integration` merged → `main` + tag semver
- [ ] `node scripts/sync-docs.mjs` + deploy `docs.lebytek.com`
- [ ] Rollback runbook probado (ver § Rollback rápido)
```

- [ ] **Step 2: Commit**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs: honest VPS checklist for message smoke and go-live gates"
```

---

### Task 4: R0 — Commit untracked superpowers docs

**Files:**
- Add: `docs/superpowers/specs/*.md`, `docs/superpowers/plans/*.md`

**Interfaces:**
- Consumes: local untracked files under `docs/superpowers/`
- Produces: specs/plans tracked in git (acceptance §6.1)

- [ ] **Step 1: Stage all superpowers docs**

```bash
cd WhatsApiLebytek
git add docs/superpowers/
git status --short docs/superpowers/
```

Expected: all `??` become `A` or `M`.

- [ ] **Step 2: Commit**

```bash
git commit -m "docs: track integration superpowers specs and plans"
```

---

### Task 5: R1 — Migration `int_mensajes`

**Files:**
- Create: `database/migrations/2026_07_02_100000_create_int_mensajes_table.php`
- Test: `tests/Feature/Api/MessageSendTest.php` (added in Task 13)

**Interfaces:**
- Consumes: spec §4.1 `int_mensajes` columns
- Produces: table `int_mensajes` with indexes for tenant isolation and idempotency

- [ ] **Step 1: Write the failing test (schema assertion helper)**

Create `tests/Feature/Api/MessageSendTest.php` with only:

```php
<?php

use Illuminate\Support\Facades\Schema;

test('int_mensajes table exists with required columns', function () {
    expect(Schema::hasTable('int_mensajes'))->toBeTrue()
        ->and(Schema::hasColumns('int_mensajes', [
            'id', 'public_id', 'tenant_id', 'instancia_id', 'direction',
            'recipient', 'body', 'status', 'green_message_id', 'error',
            'payload_hash', 'sent_at', 'created_at', 'updated_at',
        ]))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Api/MessageSendTest.php --filter="int_mensajes table"`

Expected: FAIL — table does not exist.

- [ ] **Step 3: Write migration**

Create `database/migrations/2026_07_02_100000_create_int_mensajes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('int_mensajes', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('core_tenants')->cascadeOnDelete();
            $table->foreignId('instancia_id')->nullable()->constrained('int_instancias')->nullOnDelete();
            $table->string('direction')->default('outbound');
            $table->string('recipient');
            $table->text('body');
            $table->string('status')->default('queued');
            $table->string('green_message_id')->nullable();
            $table->text('error')->nullable();
            $table->string('payload_hash')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'public_id']);
            $table->unique(['tenant_id', 'payload_hash']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('int_mensajes');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Api/MessageSendTest.php --filter="int_mensajes table"`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_02_100000_create_int_mensajes_table.php tests/Feature/Api/MessageSendTest.php
git commit -m "feat(api): add int_mensajes migration"
```

---

### Task 6: R1 — Migration `int_webhooks` (audit only)

**Files:**
- Create: `database/migrations/2026_07_02_100001_create_int_webhooks_table.php`
- Test: extend `tests/Feature/Api/MessageSendTest.php`

**Interfaces:**
- Consumes: spec §4.1 `int_webhooks` columns
- Produces: audit table; **no** controller changes in this remediation

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Api/MessageSendTest.php`:

```php
test('int_webhooks table exists with required columns', function () {
    expect(Schema::hasTable('int_webhooks'))->toBeTrue()
        ->and(Schema::hasColumns('int_webhooks', [
            'id', 'event_id', 'type_webhook', 'id_instance', 'payload',
            'processed_at', 'tenant_id', 'created_at', 'updated_at',
        ]))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Api/MessageSendTest.php --filter="int_webhooks table"`

Expected: FAIL

- [ ] **Step 3: Write migration**

Create `database/migrations/2026_07_02_100001_create_int_webhooks_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('int_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type_webhook');
            $table->string('id_instance')->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('tenant_id')->nullable()->constrained('core_tenants')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'type_webhook']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('int_webhooks');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Api/MessageSendTest.php --filter="int_webhooks table"`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_02_100001_create_int_webhooks_table.php tests/Feature/Api/MessageSendTest.php
git commit -m "feat(api): add int_webhooks audit table"
```

---

### Task 7: R1 — `Mensaje` model and factory

**Files:**
- Create: `app/Models/Integration/Mensaje.php`
- Create: `database/factories/Integration/MensajeFactory.php`
- Test: `tests/Feature/Api/MessageSendTest.php`

**Interfaces:**
- Consumes: `int_mensajes` schema (Task 5)
- Produces: `App\Models\Integration\Mensaje` with `public_id` route key

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Api/MessageSendTest.php`:

```php
use App\Models\Integration\Mensaje;

test('mensaje factory creates outbound queued row', function () {
    $mensaje = Mensaje::factory()->create([
        'direction' => 'outbound',
        'status' => 'queued',
    ]);

    expect($mensaje->public_id)->toBeString()->not->toBeEmpty()
        ->and($mensaje->direction)->toBe('outbound')
        ->and($mensaje->status)->toBe('queued');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Api/MessageSendTest.php --filter="mensaje factory"`

Expected: FAIL — class not found.

- [ ] **Step 3: Write model and factory**

Create `app/Models/Integration/Mensaje.php`:

```php
<?php

namespace App\Models\Integration;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\Integration\MensajeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'tenant_id',
    'instancia_id',
    'direction',
    'recipient',
    'body',
    'status',
    'green_message_id',
    'error',
    'payload_hash',
    'sent_at',
])]
class Mensaje extends Model
{
    /** @use HasFactory<MensajeFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'int_mensajes';

    protected static function booted(): void
    {
        static::creating(function (Mensaje $mensaje): void {
            if (empty($mensaje->public_id)) {
                $mensaje->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function instancia(): BelongsTo
    {
        return $this->belongsTo(Instancia::class, 'instancia_id');
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
```

Create `database/factories/Integration/MensajeFactory.php`:

```php
<?php

namespace Database\Factories\Integration;

use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use App\Models\Integration\Mensaje;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Mensaje> */
class MensajeFactory extends Factory
{
    protected $model = Mensaje::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'instancia_id' => Instancia::factory(),
            'direction' => 'outbound',
            'recipient' => '5215512345678',
            'body' => 'Test message',
            'status' => 'queued',
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Api/MessageSendTest.php --filter="mensaje factory"`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/Integration/Mensaje.php database/factories/Integration/MensajeFactory.php tests/Feature/Api/MessageSendTest.php
git commit -m "feat(api): add Mensaje model and factory"
```

---

### Task 8: R1 — Permissions `mensajes.*`

**Files:**
- Modify: `config/permissions.php`
- Test: `tests/Feature/Rbac/RbacSeederTest.php`

**Interfaces:**
- Consumes: `RolesAndPermissionsSeeder` reads `config/permissions.php`
- Produces: Sanctum permissions `mensajes.enviar`, `mensajes.ver`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Rbac/RbacSeederTest.php`:

```php
use Spatie\Permission\Models\Permission;

test('seeder creates mensajes permissions for sanctum guard', function () {
    expect(Permission::where('guard_name', 'sanctum')->whereIn('name', [
        'mensajes.enviar',
        'mensajes.ver',
    ])->count())->toBe(2);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Rbac/RbacSeederTest.php --filter="mensajes permissions"`

Expected: FAIL — count 0.

- [ ] **Step 3: Add permissions to config**

In `config/permissions.php`, add to **`nucleo`** array after `'instancias.eliminar'`:

```php
        'mensajes.enviar',
        'mensajes.ver',
```

Add to **`platform_service`** array after `'instancias.eliminar'`:

```php
        'mensajes.enviar',
        'mensajes.ver',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Rbac/RbacSeederTest.php --filter="mensajes permissions"`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add config/permissions.php tests/Feature/Rbac/RbacSeederTest.php
git commit -m "feat(api): add mensajes RBAC permissions"
```

---

### Task 9: R1 — `InstanceClient::sendMessage`

**Files:**
- Modify: `app/Services/GreenApi/InstanceClient.php`
- Create: `tests/Unit/GreenApi/InstanceClientSendMessageTest.php`

**Interfaces:**
- Consumes: Green API `POST waInstance{id}/sendMessage/{token}` with body `{chatId, message}`
- Produces: `InstanceClient::sendMessage(string $recipient, string $body): string` returning `idMessage`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/GreenApi/InstanceClientSendMessageTest.php`:

```php
<?php

use App\Services\GreenApi\InstanceClient;
use Illuminate\Support\Facades\Http;

test('sendMessage posts chatId and returns idMessage', function () {
    Http::fake([
        '*sendMessage*' => Http::response(['idMessage' => 'MSG123'], 200),
    ]);

    $client = new InstanceClient(
        'https://api.green-api.com',
        '1101000001',
        'secret-token',
    );

    $id = $client->sendMessage('5215512345678', 'Hola Lebytek');

    expect($id)->toBe('MSG123');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'waInstance1101000001/sendMessage/secret-token')
            && $request['chatId'] === '5215512345678@c.us'
            && $request['message'] === 'Hola Lebytek';
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/GreenApi/InstanceClientSendMessageTest.php`

Expected: FAIL — method not defined.

- [ ] **Step 3: Implement sendMessage**

Add to `app/Services/GreenApi/InstanceClient.php` before `private function instanceUrl`:

```php
    public function sendMessage(string $recipient, string $body): string
    {
        $chatId = str_contains($recipient, '@') ? $recipient : $recipient.'@c.us';
        $url = $this->instanceUrl('sendMessage');
        $response = Http::timeout(30)->post($url, [
            'chatId' => $chatId,
            'message' => $body,
        ]);

        if (! $response->successful()) {
            throw new GreenApiException(
                'sendMessage failed: '.$response->body(),
                $response->status(),
                $response->json(),
            );
        }

        $idMessage = (string) ($response->json('idMessage') ?? '');

        if ($idMessage === '') {
            throw new GreenApiException('sendMessage missing idMessage', $response->status(), $response->json());
        }

        return $idMessage;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/GreenApi/InstanceClientSendMessageTest.php`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/GreenApi/InstanceClient.php tests/Unit/GreenApi/InstanceClientSendMessageTest.php
git commit -m "feat(api): add InstanceClient sendMessage for Green API"
```

---

### Task 10: R1 — `MessageSendService`

**Files:**
- Create: `app/Services/Messaging/MessageSendService.php`
- Test: covered by Task 13 feature tests (write service first, then controller tests)

**Interfaces:**
- Consumes: `Mensaje`, `Instancia`, `TransactionalMessageJob`, `WhatsappModuleGuard`
- Produces: `queueOutbound(int $tenantId, Instancia $instancia, string $recipient, string $body, string $idempotencyKey): array{mensaje: Mensaje, created: bool}`

- [ ] **Step 1: Create service**

Create `app/Services/Messaging/MessageSendService.php`:

```php
<?php

namespace App\Services\Messaging;

use App\Jobs\TransactionalMessageJob;
use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use App\Models\Integration\Mensaje;
use App\Services\GreenApi\WhatsappModuleGuard;
use Illuminate\Support\Facades\DB;

class MessageSendService
{
    public function __construct(
        private readonly WhatsappModuleGuard $moduleGuard,
    ) {}

    /**
     * @return array{mensaje: Mensaje, created: bool}
     */
    public function queueOutbound(
        int $tenantId,
        Instancia $instancia,
        string $recipient,
        string $body,
        string $idempotencyKey,
    ): array {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $this->moduleGuard->ensureEnabled($tenant);

        if ($instancia->tenant_id !== $tenantId) {
            abort(404);
        }

        if ($instancia->status !== 'authorized') {
            abort(409, 'Instance not authorized for sending.');
        }

        $payloadHash = hash('sha256', $tenantId.':'.$idempotencyKey);

        $existing = Mensaje::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('payload_hash', $payloadHash)
            ->first();

        if ($existing !== null) {
            return ['mensaje' => $existing, 'created' => false];
        }

        $mensaje = DB::transaction(function () use ($tenantId, $instancia, $recipient, $body, $payloadHash): Mensaje {
            return Mensaje::query()->create([
                'tenant_id' => $tenantId,
                'instancia_id' => $instancia->id,
                'direction' => 'outbound',
                'recipient' => preg_replace('/\D+/', '', $recipient) ?? $recipient,
                'body' => $body,
                'status' => 'queued',
                'payload_hash' => $payloadHash,
            ]);
        });

        TransactionalMessageJob::dispatch($mensaje->id);

        return ['mensaje' => $mensaje->fresh(), 'created' => true];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Messaging/MessageSendService.php
git commit -m "feat(api): add MessageSendService with idempotency"
```

---

### Task 11: R1 — Request, resource, controller, routes

**Files:**
- Create: `app/Http/Requests/Api/V1/StoreMessageRequest.php`
- Create: `app/Http/Resources/Api/V1/MessageResource.php`
- Create: `app/Http/Controllers/Api/V1/MessageController.php`
- Modify: `routes/api.php`

**Interfaces:**
- Consumes: `MessageSendService::queueOutbound()`, `MessageResource`
- Produces: routes `api.v1.messages.store`, `api.v1.messages.show`

- [ ] **Step 1: Write failing feature test for POST 202**

Replace body of `tests/Feature/Api/MessageSendTest.php` (keep schema tests) and add:

```php
<?php

use App\Jobs\TransactionalMessageJob;
use App\Models\Core\Module;
use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use App\Models\Integration\Mensaje;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Bus::fake([TransactionalMessageJob::class]);
});

// ... keep schema + factory tests from Tasks 5–7 ...

test('tenant token can POST messages and dispatch job', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create(['tenant_id' => $tenant->id, 'module_key' => 'whatsapp', 'is_enabled' => true]);
    $instancia = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
        'id_instance' => '1101000001',
        'api_token_instance' => encrypt('green-secret'),
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo(['mensajes.enviar', 'mensajes.ver']);
    $token = $client->createToken('client', ['mensajes.enviar', 'mensajes.ver'])->plainTextToken;

    $headers = idempotencyHeaders();

    $response = $this->withToken($token)
        ->postJson(route('api.v1.messages.store'), [
            'recipient' => '5215512345678',
            'body' => 'Test Lebytek API',
            'instancePublicId' => $instancia->public_id,
        ], $headers);

    $response->assertAccepted()
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('recipient', '5215512345678');

    Bus::assertDispatched(TransactionalMessageJob::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Api/MessageSendTest.php --filter="tenant token can POST"`

Expected: FAIL — route not defined.

- [ ] **Step 3: Create request, resource, controller**

Create `app/Http/Requests/Api/V1/StoreMessageRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'max:32'],
            'body' => ['required', 'string', 'max:4096'],
            'instancePublicId' => ['required', 'string'],
        ];
    }
}
```

Create `app/Http/Resources/Api/V1/MessageResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\ApiResource;
use App\Models\Integration\Mensaje;
use Illuminate\Http\Request;

/** @mixin Mensaje */
class MessageResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'direction' => $this->direction,
            'recipient' => $this->recipient,
            'body' => $this->body,
            'status' => $this->status,
            'error' => $this->error,
            'sentAt' => $this->sent_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

Create `app/Http/Controllers/Api/V1/MessageController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMessageRequest;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Integration\Instancia;
use App\Models\Integration\Mensaje;
use App\Services\GreenApi\InstanceProvisioningService;
use App\Services\Messaging\MessageSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Messages
 *
 * @authenticated
 */
class MessageController extends Controller
{
    public function __construct(
        private readonly MessageSendService $messageSendService,
        private readonly InstanceProvisioningService $provisioningService,
    ) {}

    public function store(StoreMessageRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantAccess($request);
        $validated = $request->validated();

        $instancia = Instancia::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('public_id', $validated['instancePublicId'])
            ->firstOrFail();

        $idempotencyKey = (string) $request->header('Idempotency-Key');

        $result = $this->messageSendService->queueOutbound(
            $tenantId,
            $instancia,
            $validated['recipient'],
            $validated['body'],
            $idempotencyKey,
        );

        return (new MessageResource($result['mensaje']))
            ->response()
            ->setStatusCode($result['created'] ? 202 : 200);
    }

    public function show(Request $request, Mensaje $mensaje): MessageResource
    {
        $tenantId = $this->resolveTenantAccess($request);

        if ($mensaje->tenant_id !== $tenantId) {
            abort(404);
        }

        return new MessageResource($mensaje);
    }

    private function resolveTenantAccess(Request $request): int
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        if ($user->isPlatformAdmin()) {
            return $this->provisioningService->resolveActingTenantId();
        }

        if ($user->tenant_id === null) {
            abort(403, 'Tenant access required.');
        }

        return $user->tenant_id;
    }
}
```

Add to `routes/api.php` inside the v1 group (after instances routes):

```php
    Route::post('/messages', [\App\Http\Controllers\Api\V1\MessageController::class, 'store'])
        ->middleware('permission:mensajes.enviar')
        ->name('api.v1.messages.store');

    Route::get('/messages/{mensaje:public_id}', [\App\Http\Controllers\Api\V1\MessageController::class, 'show'])
        ->middleware('permission:mensajes.ver')
        ->withoutMiddleware('api.idempotency')
        ->name('api.v1.messages.show');
```

Add import at top of `routes/api.php`:

```php
use App\Http\Controllers\Api\V1\MessageController;
```

And use `MessageController::class` in routes if preferred over FQCN.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Api/MessageSendTest.php --filter="tenant token can POST"`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Api/V1/StoreMessageRequest.php app/Http/Resources/Api/V1/MessageResource.php app/Http/Controllers/Api/V1/MessageController.php routes/api.php tests/Feature/Api/MessageSendTest.php
git commit -m "feat(api): add POST/GET /messages endpoints"
```

---

### Task 12: R1 — `TransactionalMessageJob` real implementation

**Files:**
- Modify: `app/Jobs/TransactionalMessageJob.php`
- Create: `tests/Unit/Queue/TransactionalMessageJobTest.php`
- Modify: `tests/Unit/Queue/HorizonQueueConfigTest.php`

**Interfaces:**
- Consumes: `InstanceClient::sendMessage()`, `Mensaje` model
- Produces: job updates `status=sent`, sets `green_message_id`, `sent_at`; on failure `status=failed`, `error`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Queue/TransactionalMessageJobTest.php`:

```php
<?php

use App\Jobs\TransactionalMessageJob;
use App\Models\Integration\Instancia;
use App\Models\Integration\Mensaje;
use Illuminate\Support\Facades\Http;

test('transactional job sends via green and marks message sent', function () {
    Http::fake([
        '*sendMessage*' => Http::response(['idMessage' => 'GA-99'], 200),
    ]);

    $instancia = Instancia::factory()->create([
        'status' => 'authorized',
        'id_instance' => '1101000001',
        'api_token_instance' => encrypt('tok'),
    ]);
    $mensaje = Mensaje::factory()->create([
        'tenant_id' => $instancia->tenant_id,
        'instancia_id' => $instancia->id,
        'status' => 'queued',
        'recipient' => '5215512345678',
        'body' => 'Hola',
    ]);

    (new TransactionalMessageJob($mensaje->id))->handle();

    $mensaje->refresh();
    expect($mensaje->status)->toBe('sent')
        ->and($mensaje->green_message_id)->toBe('GA-99')
        ->and($mensaje->sent_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Queue/TransactionalMessageJobTest.php`

Expected: FAIL — job does not update status.

- [ ] **Step 3: Replace job implementation**

Replace `app/Jobs/TransactionalMessageJob.php` with:

```php
<?php

namespace App\Jobs;

use App\Exceptions\GreenApiException;
use App\Jobs\Middleware\RateLimitedWithRedis;
use App\Models\Integration\Mensaje;
use App\Services\GreenApi\InstanceClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TransactionalMessageJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $mensajeId,
    ) {
        $this->onQueue('transactional');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $mensaje = Mensaje::query()->withoutGlobalScope('tenant')->find($this->mensajeId);
        $tenantKey = $mensaje?->tenant_id ?? 'unknown';

        return [
            new RateLimitedWithRedis("green-api:tenant:{$tenantKey}", maxAttempts: 30, decaySeconds: 60),
        ];
    }

    public function handle(): void
    {
        $mensaje = Mensaje::query()
            ->withoutGlobalScope('tenant')
            ->with('instancia')
            ->find($this->mensajeId);

        if ($mensaje === null || $mensaje->status !== 'queued') {
            return;
        }

        $instancia = $mensaje->instancia;

        if ($instancia === null || $instancia->id_instance === null || $instancia->api_token_instance === null) {
            $mensaje->update(['status' => 'failed', 'error' => 'Instance credentials missing']);

            return;
        }

        try {
            $client = new InstanceClient(
                (string) config('services.green_api.base_url'),
                (string) $instancia->id_instance,
                (string) $instancia->api_token_instance,
            );

            $idMessage = $client->sendMessage($mensaje->recipient, $mensaje->body);

            $mensaje->update([
                'status' => 'sent',
                'green_message_id' => $idMessage,
                'sent_at' => now(),
                'error' => null,
            ]);
        } catch (GreenApiException $e) {
            Log::warning('TransactionalMessageJob Green API failure', [
                'mensaje_id' => $mensaje->id,
                'error' => $e->getMessage(),
            ]);

            if ($this->attempts() >= $this->tries) {
                $mensaje->update([
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }
}
```

Update `tests/Unit/Queue/HorizonQueueConfigTest.php` — replace old constructor args with:

```php
    $transactional = new TransactionalMessageJob(1);
```

(and same for `$job` variable in that file)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Queue/TransactionalMessageJobTest.php tests/Unit/Queue/HorizonQueueConfigTest.php`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/TransactionalMessageJob.php tests/Unit/Queue/TransactionalMessageJobTest.php tests/Unit/Queue/HorizonQueueConfigTest.php
git commit -m "feat(api): implement TransactionalMessageJob Green send"
```

---

### Task 13: R1 — Complete `MessageSendTest` coverage

**Files:**
- Modify: `tests/Feature/Api/MessageSendTest.php`

**Interfaces:**
- Consumes: routes from Task 11
- Produces: regression tests for idempotency, 409 unauthorized instance, cross-tenant 404, GET show

- [ ] **Step 1: Add remaining tests**

Append to `tests/Feature/Api/MessageSendTest.php`:

```php
test('POST messages is idempotent by Idempotency-Key', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create(['tenant_id' => $tenant->id, 'module_key' => 'whatsapp', 'is_enabled' => true]);
    $instancia = Instancia::factory()->create(['tenant_id' => $tenant->id, 'status' => 'authorized']);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo(['mensajes.enviar', 'mensajes.ver']);
    $token = $client->createToken('client', ['mensajes.enviar'])->plainTextToken;

    $payload = [
        'recipient' => '5215512345678',
        'body' => 'Once',
        'instancePublicId' => $instancia->public_id,
    ];
    $headers = ['Idempotency-Key' => 'fixed-key-abc'];

    $first = $this->withToken($token)->postJson(route('api.v1.messages.store'), $payload, $headers)->assertAccepted();
    $second = $this->withToken($token)->postJson(route('api.v1.messages.store'), $payload, $headers)->assertOk();

    expect($second->json('publicId'))->toBe($first->json('publicId'));
    expect(Mensaje::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
    Bus::assertDispatchedTimes(TransactionalMessageJob::class, 1);
});

test('POST messages returns 409 when instance not authorized', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create(['tenant_id' => $tenant->id, 'module_key' => 'whatsapp', 'is_enabled' => true]);
    $instancia = Instancia::factory()->create(['tenant_id' => $tenant->id, 'status' => 'waiting_qr']);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo('mensajes.enviar');
    $token = $client->createToken('client', ['mensajes.enviar'])->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.messages.store'), [
            'recipient' => '5215512345678',
            'body' => 'Fail',
            'instancePublicId' => $instancia->public_id,
        ], idempotencyHeaders())
        ->assertStatus(409);
});

test('tenant cannot read another tenant message', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $mensaje = Mensaje::factory()->create(['tenant_id' => $tenantA->id]);

    $clientB = User::factory()->forTenant($tenantB)->create();
    $clientB->givePermissionTo('mensajes.ver');
    $tokenB = $clientB->createToken('client', ['mensajes.ver'])->plainTextToken;

    $this->withToken($tokenB)
        ->getJson(route('api.v1.messages.show', $mensaje->public_id))
        ->assertNotFound();
});

test('GET messages returns sent status', function () {
    $tenant = Tenant::factory()->create();
    $mensaje = Mensaje::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo('mensajes.ver');
    $token = $client->createToken('client', ['mensajes.ver'])->plainTextToken;

    $this->withToken($token)
        ->getJson(route('api.v1.messages.show', $mensaje->public_id))
        ->assertOk()
        ->assertJsonPath('status', 'sent');
});
```

- [ ] **Step 2: Run full message test file**

Run: `php artisan test tests/Feature/Api/MessageSendTest.php`

Expected: all PASS

- [ ] **Step 3: Run full api test suite**

Run: `php artisan test tests/Feature/Api tests/Unit/GreenApi tests/Unit/Queue`

Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Api/MessageSendTest.php
git commit -m "test(api): cover message send idempotency and RBAC"
```

---

### Task 14: R1 — Contract + Scribe regeneration

**Files:**
- Modify: `docs/integration/waapi-api-contract.md`
- Modify: `Lebytek_Framework/docs/integration/waapi-api-contract.md` (mirror after api change)

**Interfaces:**
- Consumes: implemented routes from Task 11
- Produces: `/messages` in “implementados” section; campaigns remain planned

- [ ] **Step 1: Move messages from planned to implemented in contract**

In `docs/integration/waapi-api-contract.md`:

1. Add new section **`## Endpoints — Fase 2a (implementados — mensajes transaccionales)`** before the planned section with full POST/GET docs (copy from spec §4.3).

2. In **`## Endpoints — Fase 2 (planned, no implementados)`** table, **remove** rows for `POST /messages` and `GET /messages/{publicId}`; keep campaigns + credentials.

3. Update platform service permissions table (line ~28) to include `mensajes.enviar`, `mensajes.ver`.

4. Update tenant token permissions (line ~41) to list `mensajes.enviar`, `mensajes.ver` as **implementado**.

- [ ] **Step 2: Regenerate Scribe (local)**

Run:

```bash
php artisan scribe:generate
php artisan test tests/Feature/Api/MessageSendTest.php
```

Expected: Scribe generates without errors; tests still PASS.

- [ ] **Step 3: Mirror contract to Framework**

```bash
cp docs/integration/waapi-api-contract.md ../Lebytek_Framework/docs/integration/waapi-api-contract.md
```

- [ ] **Step 4: Commit (api repo)**

```bash
git add docs/integration/waapi-api-contract.md
git commit -m "docs: mark /messages endpoints as implemented in API contract"
```

- [ ] **Step 5: Commit (Framework repo)**

```bash
cd ../Lebytek_Framework
git add docs/integration/waapi-api-contract.md
git commit -m "docs: sync API contract with /messages implemented"
```

---

### Task 15: R1.7 — Demo token abilities in Framework

**Files:**
- Modify: `Lebytek_Framework/app/Application/Marketing/LeadApiProvisioningService.php:62-66`
- Modify: `Lebytek_Framework/scripts/resend-lead-credentials.php:65`

**Interfaces:**
- Consumes: api accepts `abilities` on `POST /tenants/{id}/tokens`
- Produces: demo tokens include `mensajes.enviar`, `mensajes.ver`

- [ ] **Step 1: Write failing integration test**

Append to `Lebytek_Framework/tests/Integration/LeadApiProvisioningServiceTest.php`:

```php
test('LeadApiProvisioningService requests mensajes abilities on tenant token', function () {
    $_ENV['LEBYTEK_API_URL'] = 'https://api.test/v1';

    $repo = new InMemoryLeadRepo();
    $repo->rows[7] = ['id' => 7, 'nombre' => 'Luis', 'email' => 'luis@test.com', 'api_tenant_public_id' => null];

    $transport = new SequenceTransport([
        ['status' => 201, 'body' => '{"publicId":"01JTENANT"}', 'error' => ''],
        ['status' => 202, 'body' => '{"publicId":"01JINST"}', 'error' => ''],
        ['status' => 201, 'body' => '{"token":"12|demo"}', 'error' => ''],
    ]);

    $api = new LebytekApiClient('https://api.test/v1', 'plat', 5, 1, $transport);
    $svc = new LeadApiProvisioningService($api, $repo, new LeadApiSpyMailer());
    $svc->provisionLead(7);

    // Third request is POST /tenants/.../tokens — inspect via custom transport wrapper if needed.
    // Simpler: subclass SequenceTransport to capture last POST body in test.
    assert_true(true); // placeholder replaced in Step 3
});
```

Implement capture in test using a small `CapturingTransport` in the same file:

```php
final class CapturingTransport implements LebytekApiTransport
{
    public ?string $lastBody = null;

    private int $i = 0;

    /** @param list<array{status:int,body:string,error:string}> */
    public function __construct(private array $responses) {}

    public function execute(string $method, string $url, array $headers, ?string $body): array
    {
        if ($method === 'POST' && str_contains($url, '/tokens')) {
            $this->lastBody = $body;
        }

        return $this->responses[$this->i++] ?? ['status' => 500, 'body' => '{}', 'error' => ''];
    }
}
```

Replace the test body with:

```php
test('LeadApiProvisioningService requests mensajes abilities on tenant token', function () {
    $_ENV['LEBYTEK_API_URL'] = 'https://api.test/v1';

    $repo = new InMemoryLeadRepo();
    $repo->rows[7] = ['id' => 7, 'nombre' => 'Luis', 'email' => 'luis@test.com', 'api_tenant_public_id' => null];

    $transport = new CapturingTransport([
        ['status' => 201, 'body' => '{"publicId":"01JTENANT"}', 'error' => ''],
        ['status' => 202, 'body' => '{"publicId":"01JINST"}', 'error' => ''],
        ['status' => 201, 'body' => '{"token":"12|demo"}', 'error' => ''],
    ]);

    $api = new LebytekApiClient('https://api.test/v1', 'plat', 5, 1, $transport);
    $svc = new LeadApiProvisioningService($api, $repo, new LeadApiSpyMailer());
    $svc->provisionLead(7);

    $decoded = json_decode($transport->lastBody ?? '{}', true);
    assert_same(
        ['instancias.ver', 'mensajes.enviar', 'mensajes.ver'],
        $decoded['abilities'] ?? [],
    );
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd Lebytek_Framework && php tests/run.php Integration`

Expected: FAIL — abilities mismatch `['instancias.ver']`.

- [ ] **Step 3: Update provisioning service and resend script**

In `LeadApiProvisioningService.php` line 65:

```php
                ['instancias.ver', 'mensajes.enviar', 'mensajes.ver'],
```

In `scripts/resend-lead-credentials.php` line 65:

```php
    $tokenResponse = $api->issueTenantToken($tenantPublicId, 'cliente-'.$slug, ['instancias.ver', 'mensajes.enviar', 'mensajes.ver']);
```

- [ ] **Step 4: Run integration tests**

Run: `php tests/run.php Integration`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Application/Marketing/LeadApiProvisioningService.php scripts/resend-lead-credentials.php tests/Integration/LeadApiProvisioningServiceTest.php
git commit -m "feat(provisioning): include mensajes abilities on demo tenant token"
```

---

### Task 16: R2.1–R2.2 — Confirm and document VPS crons

**Files:**
- Modify: `docs/integration/VPS_CHECKLIST.md` (capture `crontab -l` output)
- Reference: `Lebytek_Framework/scripts/lebytek-api-health.php`, `scripts/expire-api-demos.php`

**Interfaces:**
- Consumes: VPS SSH access (`lebytek-vps`)
- Produces: checklist items `[x]` with date + crontab snippet

- [ ] **Step 1: SSH and inspect crontab**

```bash
ssh lebytek-vps
sudo crontab -l -u lebytek
```

Expected: entries for health (every 5 min) and expire (daily 03:00). If missing, add:

```cron
*/5 * * * * /usr/bin/php /home/lebytek/htdocs/lebytek.com/scripts/lebytek-api-health.php >> /home/lebytek/logs/api-health.log 2>&1
0 3 * * * /usr/bin/php /home/lebytek/htdocs/lebytek.com/scripts/expire-api-demos.php >> /home/lebytek/logs/expire-demos.log 2>&1
```

- [ ] **Step 2: Paste confirmed crontab into VPS_CHECKLIST.md**

Mark R2 cron items `[x]` with date.

- [ ] **Step 3: Commit**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs: confirm VPS health and expire demo crons"
```

---

### Task 17: R2.3 — Smoke send script

**Files:**
- Create: `Lebytek_Framework/scripts/smoke-send-test-message.php`

**Interfaces:**
- Consumes: env `LEBYTEK_API_URL`, tenant token + instance public id + recipient from argv
- Produces: CLI exit 0 when POST /messages returns 202

- [ ] **Step 1: Create script**

Create `Lebytek_Framework/scripts/smoke-send-test-message.php`:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use App\Infrastructure\Integrations\LebytekApi\LebytekApiClient;
use Lebytek\Framework\Kernel\EnvLoader;

if ($argc < 5) {
    fwrite(STDERR, "Usage: php scripts/smoke-send-test-message.php <tenantToken> <instancePublicId> <recipientE164> <body>\n");
    exit(1);
}

[, $token, $instancePublicId, $recipient, $body] = $argv;

$baseUrl = rtrim((string) EnvLoader::get('LEBYTEK_API_URL', 'https://api.lebytek.com/api/v1'), '/');

$client = new LebytekApiClient($baseUrl, $token);

$idempotencyKey = bin2hex(random_bytes(16));

$response = (new ReflectionClass($client))->getMethod('request');
// Use public wrapper: extend LebytekApiClient with sendMessage method OR raw curl:
$ch = curl_init($baseUrl.'/messages');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer '.$token,
        'Content-Type: application/json',
        'Accept: application/json',
        'Idempotency-Key: '.$idempotencyKey,
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'recipient' => $recipient,
        'body' => $body,
        'instancePublicId' => $instancePublicId,
    ], JSON_THROW_ON_ERROR),
]);
$raw = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

fwrite(STDOUT, "HTTP {$status}\n{$raw}\n");

exit($status === 202 ? 0 : 1);
```

> **Note:** Prefer adding `LebytekApiClient::sendMessage()` in a follow-up commit if you want typed client usage; curl is acceptable for ops smoke script.

- [ ] **Step 2: Document usage in VPS_CHECKLIST.md**

Add under message smoke section:

```bash
php scripts/smoke-send-test-message.php "$TENANT_TOKEN" "$INSTANCE_PUBLIC_ID" "521XXXXXXXXXX" "Test Lebytek API"
```

- [ ] **Step 3: Commit (Framework)**

```bash
git add scripts/smoke-send-test-message.php
git commit -m "chore: add smoke script for POST /messages"
```

---

### Task 18: R1 smoke — Manual E2E message (gate for R3)

**Files:**
- Modify: `docs/integration/VPS_CHECKLIST.md`

**Interfaces:**
- Consumes: deployed api with Task 11–15 merged; authorized WhatsApp instance
- Produces: checklist steps 2–3 marked `[x]` with date

- [ ] **Step 1: Deploy api to VPS**

```bash
ssh lebytek-vps
cd /home/lebytek-api/htdocs/api.lebytek.com
sudo -u lebytek-api git pull origin main
sudo -u lebytek-api composer install --no-dev
sudo -u lebytek-api php artisan migrate --force
supervisorctl restart lebytek-api-horizon:*
```

- [ ] **Step 2: Deploy Framework branch with Task 15**

```bash
cd /home/lebytek/htdocs/lebytek.com
sudo -u lebytek git pull origin feature/backoffice-api-integration
sudo -u lebytek composer install --no-dev
```

- [ ] **Step 3: Run manual smoke per spec §6.3**

1. Provision demo lead → scan QR until instance `authorized`
2. Run smoke script or curl from spec §5 R1
3. Confirm WhatsApp received on phone
4. `GET /messages/{publicId}` → `status=sent`

- [ ] **Step 4: Update VPS_CHECKLIST with date + operator initials**

Mark remediation steps 2–3 `[x]`.

- [ ] **Step 5: Commit checklist**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs: record message smoke E2E pass"
```

---

### Task 19: R3.1 — Sync and deploy docs hub

**Files:**
- Run: `docs.lebytek.com/scripts/sync-docs.mjs`
- Deploy per `docs.lebytek.com` hosting docs

**Interfaces:**
- Consumes: updated `waapi-api-contract.md` from Task 14
- Produces: live `docs.lebytek.com` showing `/messages` implemented

- [ ] **Step 1: Sync docs locally**

```bash
cd docs.lebytek.com
node scripts/sync-docs.mjs
```

Expected: `✓ integration/waapi-api-contract.md` and related files copied.

- [ ] **Step 2: Deploy to VPS/nginx site**

Follow existing docs hub deploy procedure (commit + pull on docs VPS, or rsync `docs/` to web root).

- [ ] **Step 3: Verify in browser**

Open: `https://docs.lebytek.com/#/integration/waapi-api-contract`

Expected: `/messages` under implementados, not planned.

- [ ] **Step 4: Mark VPS_CHECKLIST step 5 `[x]` with date**

---

### Task 20: R3.2 — Merge Framework to main + tag

**Files:**
- Repo: `Lebytek_Framework`
- Branch: `feature/backoffice-api-integration` → `main`

**Interfaces:**
- Consumes: R1 smoke green (Task 18)
- Produces: semver tag on `main`

- [ ] **Step 1: Pre-merge verification**

```bash
cd Lebytek_Framework
php tests/run.php
php tests/run.php Integration
```

Expected: all green.

- [ ] **Step 2: Open PR and merge**

```bash
git checkout feature/backoffice-api-integration
git pull origin feature/backoffice-api-integration
gh pr create --base main --head feature/backoffice-api-integration --title "Integration: back-office api + messages demo token" --body "## Summary
- Provisioning/deprovision demo lifecycle (2b)
- Demo token includes mensajes abilities (2a consumer)

## Test plan
- [x] php tests/run.php Integration
- [x] Manual message smoke on VPS
"
gh pr merge --merge
```

- [ ] **Step 3: Tag release**

```bash
git checkout main
git pull origin main
git tag v1.0.0-beta.1
git push origin v1.0.0-beta.1
```

---

### Task 21: R3.3–R3.5 — VPS deploy main + DNS cutover + post-cutover smoke

**Files:**
- Modify: `docs/integration/VPS_CHECKLIST.md`
- Ops: DNS registrar for `lebytek.com`

**Interfaces:**
- Consumes: Framework `main` tagged; api `main` with `/messages`
- Produces: production cutover per spec §6.4

- [ ] **Step 1: Lower DNS TTL 24h before cutover**

Document date in checklist.

- [ ] **Step 2: Deploy lebytek.com from main**

```bash
ssh lebytek-vps
cd /home/lebytek/htdocs/lebytek.com
sudo -u lebytek git fetch origin main
sudo -u lebytek git checkout main
sudo -u lebytek git pull origin main
sudo -u lebytek composer install --no-dev
php scripts/lebytek-api-health.php
```

Expected: exit 0.

- [ ] **Step 3: DNS A/AAAA → VPS IP**

Update registrar; wait propagation.

- [ ] **Step 4: Post-cutover smoke (spec §6.3 + §6.4)**

- [ ] `https://lebytek.com/` loads
- [ ] Admin login works
- [ ] Provision demo + message smoke still green
- [ ] `crontab -l -u lebytek` captured in checklist

- [ ] **Step 5: Update VPS_CHECKLIST DNS section**

Change “Do not point DNS” to `[x]` with date.

- [ ] **Step 6: Commit checklist + integration README phase 3 status**

Update README roadmap row 2a → ✅, row 3 → ✅.

```bash
git add docs/integration/README.md docs/integration/VPS_CHECKLIST.md
git commit -m "docs: mark remediation 2a/3 complete after go-live smoke"
```

---

### Task 22: R4 — Gate to Phase 4/5 (verification only)

**Files:**
- Reference: `docs/superpowers/specs/2026-07-02-integration-phase4-5-design.md`

**Interfaces:**
- Consumes: all §6 acceptance criteria
- Produces: explicit go/no-go before waapi panel work

- [ ] **Step 1: Walk acceptance checklist §6.1–6.4**

| Criterion | Task |
|-----------|------|
| README honest phases | Task 1, 21 |
| Contract `/messages` implementados | Task 14 |
| No “Fase 2/3 done” without 2a/2b split | Tasks 1–2 |
| Specs/plans in git | Task 4 |
| POST/GET messages | Tasks 11–13 |
| Demo token `mensajes.enviar` | Task 15 |
| E2E smoke documented | Task 18 |
| DNS + main deploy + crons | Tasks 16, 21 |

- [ ] **Step 2: Only if all checked — begin Phase 4/5 plan**

Execute `2026-07-02-integration-phase4-5-design.md` via writing-plans / executing-plans.

---

## Self-review (plan author checklist)

**Spec coverage:** R0→R4 from remediation spec §5 mapped to Tasks 1–22. Campaigns, credentials PUT, inbound webhook processing explicitly deferred (YAGNI).  
**Placeholder scan:** No TBD steps; each code step includes concrete files and snippets.  
**Type consistency:** `TransactionalMessageJob(int $mensajeId)`, abilities array `['instancias.ver','mensajes.enviar','mensajes.ver']`, route names `api.v1.messages.store/show` used consistently.

**Gap note:** `LebytekApiClient` has no `sendMessage()` wrapper — smoke script uses curl; optional P2 helper can be added without blocking remediation.

---

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-02-integration-roadmap-remediation.md`. Two execution options:

**1. Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?
