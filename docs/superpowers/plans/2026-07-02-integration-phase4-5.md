# Integration Phase 4 + Phase 5 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the read-only client panel at `waapi.lebytek.com` (token login, instance status, QR, usage summary) and close platform maturity items — `/messages` carry-over, docs live, VPS crons, observability, production go-live, and honest integration checklists.

**Architecture:** Enfoque **B** (messages before DNS) + waapi panel **C or B** per VPS audit. Clients authenticate to waapi with Sanctum tenant token stored server-side only; waapi proxies read calls to `api.lebytek.com`. WhatsApp technical work stays exclusively in Laravel api (Green API adapter, jobs, webhooks). Framework back-office provisions tokens and emails dashboard link via `MKT_EMAIL_DASHBOARD_URL`.

**Tech Stack:** Laravel 11+ (WhatsApiLebytek), Lebytek Framework Onion (Lebytek_Framework), Pest, Redis/Horizon, Docsify hub (`docs.lebytek.com`), bash VPS ops, Green API HTTP adapter.

**Spec:** `docs/superpowers/specs/2026-07-02-integration-phase4-5-design.md`

## Global Constraints

- Fase 4 = panel waapi **solo lectura**; waapi **NUNCA** llama Green API directo (`grep -r green-api.com app/` → 0 hits salvo docs).
- Fase 5 carry-over: **`/messages` antes de DNS cutover** (Enfoque B).
- Token cliente: sesión **server-side** en waapi; prohibido `localStorage` plano.
- waapi **no** orquesta provisioning; solo consume api con Bearer tenant token.
- Idempotencia writes: header `Idempotency-Key` obligatorio (middleware existente).
- JSON API: **camelCase**; claves públicas **ULID** (`publicId`).
- Permisos slug: `modulo.accion` (`mensajes.enviar`, `mensajes.ver`, etc.).
- Token demo Framework: `['instancias.ver', 'mensajes.enviar', 'mensajes.ver']` tras `/messages` live.
- Env api prod: `SESSION_SECURE_COOKIE=true`, `/register` deshabilitado.
- `PUT /credentials/green-api`: **501** documentado en v1 (YAGNI BYO credentials).
- Facturación automática, 2FA admin, panel waapi escritura: **fuera de scope**.
- **No** commitear `.env`, tokens, ni secretos.
- Tests CI api/Framework: **sin** llamadas reales a Green API (Http::fake / transport mock).
- Checklists: ningún `[x]` sin fecha/evidencia.

---

## File map (before coding)

| File | Action | Responsibility |
|------|--------|----------------|
| `WhatsApiLebytek/database/migrations/2026_07_02_100000_create_int_mensajes_table.php` | **Create** | Outbound/inbound messages |
| `WhatsApiLebytek/database/migrations/2026_07_02_100001_create_int_webhooks_table.php` | **Create** | Webhook audit + dedup |
| `WhatsApiLebytek/database/migrations/2026_07_02_110000_create_dom_campanias_tables.php` | **Create** | Campaign MVP (optional Task 9) |
| `WhatsApiLebytek/app/Models/Integration/Mensaje.php` | **Create** | Message Eloquent model |
| `WhatsApiLebytek/app/Models/Integration/WebhookEvent.php` | **Create** | Webhook persistence |
| `WhatsApiLebytek/app/Services/GreenApi/InstanceClient.php` | Modify | Add `sendMessage()` |
| `WhatsApiLebytek/app/Services/Messaging/MessageService.php` | **Create** | Queue outbound messages |
| `WhatsApiLebytek/app/Http/Controllers/Api/V1/MessageController.php` | **Create** | POST/GET messages |
| `WhatsApiLebytek/app/Http/Resources/Api/V1/MessageResource.php` | **Create** | camelCase JSON |
| `WhatsApiLebytek/app/Http/Requests/Api/V1/StoreMessageRequest.php` | **Create** | Validation |
| `WhatsApiLebytek/app/Jobs/TransactionalMessageJob.php` | Modify | Real Green send + usage hook |
| `WhatsApiLebytek/app/Http/Controllers/Api/V1/IncomingWebhookController.php` | Modify | inbound + webhooks table |
| `WhatsApiLebytek/app/Http/Controllers/Api/V1/CredentialsController.php` | **Create** | 501 stub |
| `WhatsApiLebytek/config/permissions.php` | Modify | `mensajes.*`, `campanias.*` |
| `WhatsApiLebytek/routes/api.php` | Modify | messages, campaigns, credentials routes |
| `WhatsApiLebytek/tests/Feature/Api/MessageSendTest.php` | **Create** | Pest feature tests |
| `Lebytek_Framework/app/Application/Marketing/LeadApiProvisioningService.php` | Modify | demo token abilities + dashboard URL |
| `Lebytek_Framework/app/Presentation/Views/emails/lead_api_credentials.php` | Modify | Dashboard CTA |
| `Lebytek_Framework/app/Infrastructure/Integrations/LebytekApi/ClientTenantApiClient.php` | **Create** | Tenant-token api reads |
| `Lebytek_Framework/app/Application/Marketing/WaapiPortalSession.php` | **Create** | Encrypted session token |
| `Lebytek_Framework/app/Presentation/Controllers/Publico/WaapiPortalController.php` | **Create** | acceso/dashboard/qr/uso |
| `Lebytek_Framework/app/Presentation/Views/publico/waapi/*.php` | **Create** | Portal + landing views |
| `Lebytek_Framework/routes/waapi_portal.php` | **Create** | `/`, `/portal/*` |
| `Lebytek_Framework/tests/Integration/WaapiPortalSessionTest.php` | **Create** | Session + no green-api |
| `docs.lebytek.com/api/content/guides/portal-cliente-waapi.md` | **Create** | Portal guide |
| `docs.lebytek.com/scripts/sync-docs.mjs` | Modify | Include portal guide |
| `WhatsApiLebytek/docs/integration/waapi-api-contract.md` | Modify | Mark endpoints implemented |
| `WhatsApiLebytek/docs/integration/VPS_CHECKLIST.md` | Modify | Phase 4/5 evidence |
| `WhatsApiLebytek/routes/auth.php` | Modify | Disable register in prod |

---

### Task 0: Re-audit §0 (VPS + codebase baseline)

**Files:**
- Reference: `docs/superpowers/specs/2026-07-02-integration-phase4-5-design.md` §0
- Modify: `docs/integration/VPS_CHECKLIST.md`

**Interfaces:**
- Consumes: spec audit table
- Produces: documented decision **Approach B vs C** for waapi (`WAAPI_DEPLOY_MODE=framework_portal` or separate site)

- [ ] **Step 1: Record api route baseline**

Run locally in `WhatsApiLebytek`:

```bash
cd c:/Users/User/OneDrive/Desktop/sistemas/WhatsApiLebytek
php artisan route:list --path=api/v1
grep -E "messages|campaigns|credentials" routes/api.php || echo "CONFIRMED: no message routes yet"
php artisan test --filter=HealthEndpoint
```

Expected: health tests PASS; no `/messages` routes.

- [ ] **Step 2: SSH waapi vhost audit**

```bash
ssh lebytek-vps
# Determine waapi document root:
ls -la /home/*/htdocs/ 2>/dev/null | grep -i waapi || true
# If site exists, note path e.g. /home/lebytek/htdocs/waapi.lebytek.com
grep -r "DocumentRoot" /etc/nginx/sites-enabled/ 2>/dev/null | grep waapi || true
crontab -u lebytek -l 2>/dev/null | grep -E "health|expire-api" || echo "NO CRON CONFIRMED"
```

Expected: note waapi path; cron health likely missing (spec §0 ⚠️).

- [ ] **Step 3: Update audit table in VPS_CHECKLIST**

Add section `## Phase 4/5 baseline (YYYY-MM-DD)` with:
- waapi vhost path + deploy mode decision (B: same Framework tree, C: separate)
- `/messages` present: no
- crontab health: yes/no with evidence

- [ ] **Step 4: Commit audit doc**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs: phase 4/5 baseline audit before implementation"
```

---

### Task 1: `int_mensajes` migration + model

**Files:**
- Create: `database/migrations/2026_07_02_100000_create_int_mensajes_table.php`
- Create: `database/factories/Integration/MensajeFactory.php`
- Create: `app/Models/Integration/Mensaje.php`
- Test: `tests/Unit/Models/MensajeTest.php`

**Interfaces:**
- Consumes: `Instancia` model, `BelongsToTenant` trait
- Produces: `App\Models\Integration\Mensaje` with `public_id`, `status` enum, route key `public_id`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Models/MensajeTest.php`:

```php
<?php

use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use App\Models\Integration\Mensaje;

test('mensaje generates public_id on create', function () {
    $tenant = Tenant::factory()->create();
    $instancia = Instancia::factory()->create(['tenant_id' => $tenant->id]);

    $mensaje = Mensaje::query()->create([
        'tenant_id' => $tenant->id,
        'instancia_id' => $instancia->id,
        'direction' => 'outbound',
        'recipient' => '5215512345678',
        'body' => 'Hola',
        'status' => 'queued',
    ]);

    expect($mensaje->public_id)->not->toBeEmpty();
    expect($mensaje->getRouteKeyName())->toBe('public_id');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Models/MensajeTest.php -v`

Expected: FAIL — class `Mensaje` not found.

- [ ] **Step 3: Write migration + model + factory**

Migration `database/migrations/2026_07_02_100000_create_int_mensajes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('int_mensajes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('core_tenants')->cascadeOnDelete();
            $table->foreignId('instancia_id')->nullable()->constrained('int_instancias')->nullOnDelete();
            $table->string('direction'); // outbound|inbound
            $table->string('recipient');
            $table->text('body');
            $table->string('status')->default('queued'); // queued|sent|delivered|failed
            $table->string('green_message_id')->nullable();
            $table->text('error')->nullable();
            $table->string('payload_hash')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

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

Model `app/Models/Integration/Mensaje.php`:

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

Add factory mirroring `InstanciaFactory` pattern (tenant_id, instancia_id, status `queued`).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Models/MensajeTest.php -v`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_02_100000_create_int_mensajes_table.php app/Models/Integration/Mensaje.php database/factories/Integration/MensajeFactory.php tests/Unit/Models/MensajeTest.php
git commit -m "feat(api): add int_mensajes model and migration"
```

---

### Task 2: `InstanceClient::sendMessage` + permissions seed

**Files:**
- Modify: `app/Services/GreenApi/InstanceClient.php`
- Modify: `config/permissions.php`
- Modify: `app/Http/Requests/Api/V1/StoreTenantTokenRequest.php`
- Modify: `app/Services/TenantTokenService.php`
- Test: `tests/Unit/GreenApi/InstanceClientSendMessageTest.php`

**Interfaces:**
- Consumes: Green API URL pattern from existing `qr()` method
- Produces: `InstanceClient::sendMessage(string $recipient, string $body): string` returning `idMessage`
- Produces: permissions `mensajes.enviar`, `mensajes.ver`, `campanias.ver`, `campanias.crear`, `campanias.enviar`, `credenciales.gestionar`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/GreenApi/InstanceClientSendMessageTest.php`:

```php
<?php

use App\Services\GreenApi\InstanceClient;
use Illuminate\Support\Facades\Http;

test('sendMessage posts chatId and message to green api', function () {
    Http::fake([
        '*/sendMessage/*' => Http::response(['idMessage' => 'BAE5CC0F8C1B0512'], 200),
    ]);

    $client = new InstanceClient('https://api.green-api.com', '110100', 'token123');
    $id = $client->sendMessage('5215512345678', 'Hola Lebytek');

    expect($id)->toBe('BAE5CC0F8C1B0512');
    Http::assertSent(fn ($req) => str_contains($req->url(), '/sendMessage/')
        && $req['chatId'] === '5215512345678@c.us'
        && $req['message'] === 'Hola Lebytek');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/GreenApi/InstanceClientSendMessageTest.php -v`

Expected: FAIL — method `sendMessage` not defined.

- [ ] **Step 3: Implement sendMessage + permissions**

Add to `InstanceClient.php`:

```php
public function sendMessage(string $recipientE164, string $body): string
{
    $chatId = str_ends_with($recipientE164, '@c.us')
        ? $recipientE164
        : $recipientE164.'@c.us';

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

    return (string) ($response->json('idMessage') ?? '');
}
```

Update `config/permissions.php` — append to `nucleo` and `platform_service`:

```php
'mensajes.enviar',
'mensajes.ver',
'campanias.ver',
'campanias.crear',
'campanias.enviar',
'credenciales.gestionar',
```

Update `StoreTenantTokenRequest.php` abilities rule:

```php
'abilities.*' => ['string', Rule::in([
    'instancias.ver',
    'mensajes.enviar',
    'mensajes.ver',
    'campanias.ver',
    'campanias.crear',
    'campanias.enviar',
])],
```

Update `TenantTokenService.php` default:

```php
$abilities ??= ['instancias.ver', 'mensajes.enviar', 'mensajes.ver'];
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Unit/GreenApi/InstanceClientSendMessageTest.php tests/Feature/Rbac/RbacSeederTest.php -v`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/GreenApi/InstanceClient.php config/permissions.php app/Http/Requests/Api/V1/StoreTenantTokenRequest.php app/Services/TenantTokenService.php tests/Unit/GreenApi/InstanceClientSendMessageTest.php
git commit -m "feat(api): add sendMessage client and mensajes permissions"
```

---

### Task 3: MessageService + MessageController + routes

**Files:**
- Create: `app/Services/Messaging/MessageService.php`
- Create: `app/Http/Requests/Api/V1/StoreMessageRequest.php`
- Create: `app/Http/Resources/Api/V1/MessageResource.php`
- Create: `app/Http/Controllers/Api/V1/MessageController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/MessageSendTest.php`

**Interfaces:**
- Consumes: `Mensaje`, `Instancia`, `TransactionalMessageJob`
- Produces: `MessageService::queueOutbound(int $tenantId, Instancia $instancia, string $recipient, string $body): Mensaje`
- Produces: routes `api.v1.messages.store`, `api.v1.messages.show`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/MessageSendTest.php`:

```php
<?php

use App\Jobs\TransactionalMessageJob;
use App\Models\Core\Module;
use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Bus::fake([TransactionalMessageJob::class]);
});

test('tenant token can queue outbound message on authorized instance', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create(['tenant_id' => $tenant->id, 'module_key' => 'whatsapp', 'is_enabled' => true]);
    $instancia = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo(['mensajes.enviar', 'mensajes.ver']);
    $token = $client->createToken('client', ['mensajes.enviar', 'mensajes.ver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson(route('api.v1.messages.store'), [
            'recipient' => '5215512345678',
            'body' => 'Test Lebytek',
            'instancePublicId' => $instancia->public_id,
        ], idempotencyHeaders());

    $response->assertAccepted()
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('recipient', '5215512345678');

    Bus::assertDispatched(TransactionalMessageJob::class);
    $this->assertDatabaseHas('int_mensajes', [
        'tenant_id' => $tenant->id,
        'instancia_id' => $instancia->id,
        'status' => 'queued',
    ]);
});

test('message send rejected when instance not authorized', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create(['tenant_id' => $tenant->id, 'module_key' => 'whatsapp', 'is_enabled' => true]);
    $instancia = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'waiting_qr',
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo('mensajes.enviar');
    $token = $client->createToken('client', ['mensajes.enviar'])->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.messages.store'), [
            'recipient' => '5215512345678',
            'body' => 'Blocked',
            'instancePublicId' => $instancia->public_id,
        ], idempotencyHeaders())
        ->assertStatus(409);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Api/MessageSendTest.php -v`

Expected: FAIL — route `api.v1.messages.store` not defined.

- [ ] **Step 3: Implement service, controller, routes**

`StoreMessageRequest.php`:

```php
public function rules(): array
{
    return [
        'recipient' => ['required', 'string', 'regex:/^\d{10,15}$/'],
        'body' => ['required', 'string', 'max:4096'],
        'instancePublicId' => ['required', 'string'],
    ];
}
```

`MessageResource.php` — fields: `publicId`, `direction`, `recipient`, `body`, `status`, `error`, `sentAt`, `createdAt`.

`MessageService.php` — validate instance belongs to tenant + `status === authorized`; create `Mensaje` with `payload_hash = hash('sha256', tenant|instance|recipient|body)`; dispatch job with `mensaje->public_id`.

`MessageController.php` — mirror tenant resolution from `InstanceController::resolveTenantAccess()`; `store` returns 202 + MessageResource; `show` returns MessageResource or 404 cross-tenant.

Add to `routes/api.php` inside v1 group:

```php
Route::post('/messages', [MessageController::class, 'store'])
    ->middleware('permission:mensajes.enviar')
    ->name('api.v1.messages.store');

Route::get('/messages/{mensaje:public_id}', [MessageController::class, 'show'])
    ->middleware('permission:mensajes.ver')
    ->withoutMiddleware('api.idempotency')
    ->name('api.v1.messages.show');
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/Api/MessageSendTest.php -v`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Messaging/MessageService.php app/Http/Controllers/Api/V1/MessageController.php app/Http/Resources/Api/V1/MessageResource.php app/Http/Requests/Api/V1/StoreMessageRequest.php routes/api.php tests/Feature/Api/MessageSendTest.php
git commit -m "feat(api): add POST/GET messages endpoints"
```

---

### Task 4: TransactionalMessageJob + TenantUsageService hook

**Files:**
- Modify: `app/Jobs/TransactionalMessageJob.php`
- Modify: `app/Services/Messaging/MessageService.php` (pass mensaje public id)
- Test: `tests/Feature/Jobs/TransactionalMessageJobTest.php`

**Interfaces:**
- Consumes: `InstanceClient::sendMessage`, `TenantUsageService::increment`
- Produces: job updates `int_mensajes.status` to `sent` or `failed`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\TransactionalMessageJob;
use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use App\Models\Integration\Mensaje;
use App\Services\TenantUsageService;
use Illuminate\Support\Facades\Http;

test('transactional job sends message and increments usage', function () {
    Http::fake(['*/sendMessage/*' => Http::response(['idMessage' => 'MSG1'], 200)]);

    $tenant = Tenant::factory()->create();
    $instancia = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
        'id_instance' => '110100',
        'api_token_instance' => encrypt('tok'),
    ]);
    $mensaje = Mensaje::factory()->create([
        'tenant_id' => $tenant->id,
        'instancia_id' => $instancia->id,
        'recipient' => '5215512345678',
        'body' => 'Hola',
        'status' => 'queued',
    ]);

    (new TransactionalMessageJob($mensaje->public_id))->handle(app(TenantUsageService::class));

    $mensaje->refresh();
    expect($mensaje->status)->toBe('sent');
    expect($mensaje->green_message_id)->toBe('MSG1');
    expect(app(TenantUsageService::class)->get($tenant, 'messages.sent'))->toBe(1);
});
```

- [ ] **Step 2: Run test — expect FAIL**

Run: `php artisan test tests/Feature/Jobs/TransactionalMessageJobTest.php -v`

- [ ] **Step 3: Implement job**

Replace stub constructor/handle in `TransactionalMessageJob.php`:

```php
public function __construct(public readonly string $mensajePublicId)
{
    $this->onQueue('transactional');
}

public function handle(TenantUsageService $usage): void
{
    $mensaje = Mensaje::query()->withoutGlobalScope('tenant')
        ->where('public_id', $this->mensajePublicId)->firstOrFail();
    $instancia = Instancia::query()->withoutGlobalScope('tenant')
        ->findOrFail($mensaje->instancia_id);

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
        $usage->increment($instancia->tenant, 'messages.sent');
    } catch (\Throwable $e) {
        $mensaje->update(['status' => 'failed', 'error' => $e->getMessage()]);
        throw $e;
    }
}
```

Update `MessageService` dispatch: `TransactionalMessageJob::dispatch($mensaje->public_id)`.

- [ ] **Step 4: Run test — expect PASS**

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(api): wire TransactionalMessageJob with usage tracking"
```

---

### Task 5: `int_webhooks` + inbound webhook handlers

**Files:**
- Create: `database/migrations/2026_07_02_100001_create_int_webhooks_table.php`
- Create: `app/Models/Integration/WebhookEvent.php`
- Modify: `app/Http/Controllers/Api/V1/IncomingWebhookController.php`
- Test: `tests/Feature/Webhooks/WebhookIncomingMessageTest.php`

**Interfaces:**
- Consumes: header `X-Event-Id` (or payload hash fallback)
- Produces: `WebhookEvent` rows; inbound `Mensaje` for `incomingMessageReceived`

- [ ] **Step 1: Write failing test** for fake `incomingMessageReceived` payload → creates inbound `Mensaje`.

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Migration**

```php
Schema::create('int_webhooks', function (Blueprint $table): void {
    $table->id();
    $table->string('event_id')->unique();
    $table->string('type_webhook');
    $table->string('id_instance')->nullable();
    $table->json('payload');
    $table->timestamp('processed_at')->nullable();
    $table->foreignId('tenant_id')->nullable()->constrained('core_tenants')->nullOnDelete();
    $table->timestamps();
});
```

Controller flow: insert `WebhookEvent` first (skip if duplicate `event_id`); route by `typeWebhook`; resolve tenant via `Instancia::where('id_instance', ...)`.

- [ ] **Step 4: Run tests — PASS**

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(api): persist webhooks and handle incoming messages"
```

---

### Task 6: Framework demo token abilities + resend script

**Files:**
- Modify: `Lebytek_Framework/app/Application/Marketing/LeadApiProvisioningService.php:62-66`
- Modify: `Lebytek_Framework/scripts/resend-lead-credentials.php:65`
- Modify: `Lebytek_Framework/tests/Integration/LeadApiProvisioningServiceTest.php`
- Test: assert `issueTenantToken` called with 3 abilities

**Interfaces:**
- Consumes: live `/messages` from Task 3
- Produces: demo tokens including `mensajes.enviar`, `mensajes.ver`

- [ ] **Step 1: Update test expectation**

In `LeadApiProvisioningServiceTest.php`, assert transport captured:

```php
['instancias.ver', 'mensajes.enviar', 'mensajes.ver']
```

- [ ] **Step 2: Run — FAIL**

Run: `cd Lebytek_Framework && php tests/run.php Integration/LeadApiProvisioningServiceTest`

- [ ] **Step 3: Change abilities in provisioning + resend script**

```php
$tokenResponse = $this->api->issueTenantToken(
    $tenantPublicId,
    'cliente-'.$slug,
    ['instancias.ver', 'mensajes.enviar', 'mensajes.ver'],
);
```

- [ ] **Step 4: Run — PASS**

- [ ] **Step 5: Commit in Lebytek_Framework**

```bash
git commit -m "feat(marketing): demo token includes mensajes abilities"
```

---

### Task 7: E2E smoke message (ops + api deploy)

**Files:**
- Modify: `docs/integration/VPS_CHECKLIST.md`

**Interfaces:**
- Consumes: Tasks 3–6 deployed to api VPS; demo token from CRUD provision

- [ ] **Step 1: Deploy api to VPS**

```bash
ssh lebytek-vps
cd /home/lebytek-api/htdocs/api.lebytek.com
sudo -u lebytek-api git pull origin main
sudo -u lebytek-api composer install --no-dev
sudo -u lebytek-api php artisan migrate --force
supervisorctl restart lebytek-api-horizon:*
```

- [ ] **Step 2: Smoke curl (replace token and instancePublicId)**

```bash
curl -X POST https://api.lebytek.com/api/v1/messages \
  -H "Authorization: Bearer $TENANT_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"recipient":"521XXXXXXXXXX","body":"Test Lebytek phase5","instancePublicId":"01J..."}'
```

Expected: `202` + WhatsApp received on test phone.

- [ ] **Step 3: Document evidence in VPS_CHECKLIST with date**

- [ ] **Step 4: Commit checklist**

---

### Task 8: waapi portal session + ClientTenantApiClient

**Files:**
- Create: `Lebytek_Framework/app/Application/Marketing/WaapiPortalSession.php`
- Create: `Lebytek_Framework/app/Infrastructure/Integrations/LebytekApi/ClientTenantApiClient.php`
- Modify: `Lebytek_Framework/config/container.php`
- Test: `Lebytek_Framework/tests/Integration/WaapiPortalSessionTest.php`

**Interfaces:**
- Consumes: `LebytekApiTransport`
- Produces: `WaapiPortalSession::login(string $token): bool` (validates via GET `/instances`)
- Produces: `ClientTenantApiClient` methods: `listInstances()`, `getInstanceQr(string $publicId)`, `getUsage()` (placeholder counts from api when endpoint exists)

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Application\Marketing\WaapiPortalSession;
use App\Infrastructure\Integrations\LebytekApi\LebytekApiTransport;

final class FakeTransport implements LebytekApiTransport
{
    /** @var list<string> */
    public array $lastHeaders = [];

    public function execute(string $method, string $url, array $headers, ?string $body): array
    {
        $this->lastHeaders = $headers;

        return [
            'status' => 200,
            'body' => json_encode(['data' => [['publicId' => '01JINST', 'status' => 'waiting_qr']]], JSON_THROW_ON_ERROR),
            'error' => '',
        ];
    }
}

$transport = new FakeTransport();
$session = new WaapiPortalSession($transport, 'https://api.lebytek.com/api/v1');
assert_true($session->login('tenant-token-plain'));
assert_true(in_array('Authorization: Bearer tenant-token-plain', $transport->lastHeaders, true));
assert_true($session->isAuthenticated());
$session->logout();
assert_true(! $session->isAuthenticated());
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Implement session (server-side only)**

`WaapiPortalSession.php`:

```php
final class WaapiPortalSession
{
    private const SESSION_KEY = 'waapi_portal';

    public function __construct(
        private readonly LebytekApiTransport $transport,
        private readonly string $baseUrl,
    ) {}

    public function login(string $plainToken): bool
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '') {
            return false;
        }
        $url = rtrim($this->baseUrl, '/').'/instances';
        $headers = [
            'Authorization: Bearer '.$plainToken,
            'Accept: application/json',
        ];
        $result = $this->transport->execute('GET', $url, $headers, null);
        if ($result['status'] !== 200) {
            return false;
        }
        $response = json_decode($result['body'], true);
        if (! is_array($response) || (! isset($response['data']) && ! array_is_list($response))) {
            return false;
        }
        Session::set(self::SESSION_KEY, [
            'token' => base64_encode($plainToken), // obfuscated at rest in session store
            'authenticated_at' => time(),
        ]);
        return true;
    }

    public function token(): ?string
    {
        $data = Session::get(self::SESSION_KEY);
        if (! is_array($data) || empty($data['token'])) {
            return null;
        }
        return base64_decode((string) $data['token'], true) ?: null;
    }

    public function isAuthenticated(): bool
    {
        return $this->token() !== null;
    }

    public function logout(): void
    {
        Session::remove(self::SESSION_KEY);
    }
}
```

`ClientTenantApiClient.php` — thin wrapper using session token (not platform token).

- [ ] **Step 4: Run — PASS**

- [ ] **Step 5: Commit**

---

### Task 9: waapi portal views + routes + landing

**Files:**
- Create: `Lebytek_Framework/routes/waapi_portal.php`
- Modify: `Lebytek_Framework/routes/web.php` (conditional include when `EnvLoader::get('WAAPI_PORTAL_ENABLED') === 'true'`)
- Create: `Lebytek_Framework/app/Presentation/Controllers/Publico/WaapiPortalController.php`
- Create: `Lebytek_Framework/app/Presentation/Views/publico/waapi/landing.php`
- Create: `Lebytek_Framework/app/Presentation/Views/publico/waapi/acceso.php`
- Create: `Lebytek_Framework/app/Presentation/Views/publico/waapi/dashboard.php`
- Create: `Lebytek_Framework/app/Presentation/Views/publico/waapi/qr.php`
- Create: `Lebytek_Framework/app/Presentation/Views/publico/waapi/uso.php`
- Reference UX: `app/Presentation/Views/publico/wa_activar.php`

**Interfaces:**
- Consumes: `WaapiPortalSession`, `ClientTenantApiClient`
- Produces: routes `/`, `/portal/acceso`, `/portal/dashboard`, `/portal/qr`, `/portal/uso`

- [ ] **Step 1: Add routes**

`routes/waapi_portal.php`:

```php
$router->get('/', [WaapiPortalController::class, 'landing']);
$router->get('/portal/acceso', [WaapiPortalController::class, 'accesoForm']);
$router->post('/portal/acceso', [WaapiPortalController::class, 'accesoSubmit'], [CsrfMiddleware::class]);
$router->get('/portal/dashboard', [WaapiPortalController::class, 'dashboard']);
$router->get('/portal/qr', [WaapiPortalController::class, 'qr']);
$router->get('/portal/uso', [WaapiPortalController::class, 'uso']);
$router->post('/portal/logout', [WaapiPortalController::class, 'logout'], [CsrfMiddleware::class]);
```

- [ ] **Step 2: Implement controller**

`accesoSubmit`: read `token` POST field → `$session->login()` → redirect dashboard or error.

`dashboard`: fetch first instance via api; show badges `authorized` / `waiting_qr`.

`qr`: proxy `GET /instances/{id}/qr`; reuse polling JS pattern from `wa_activar.php` (refresh QR every 20s via api proxy endpoint `/portal/qr/data` if needed).

`uso`: show placeholder or call usage endpoint when available.

- [ ] **Step 3: Verify no green-api references**

Run: `grep -r "green-api" Lebytek_Framework/app/Presentation/Controllers/Publico/WaapiPortalController.php Lebytek_Framework/app/Infrastructure/Integrations/LebytekApi/ || echo OK`

Expected: OK (no hits)

- [ ] **Step 4: Manual smoke locally**

```bash
cd Lebytek_Framework
# .env: WAAPI_PORTAL_ENABLED=true, LEBYTEK_API_URL=...
php -S localhost:8080 -t public
# Open /portal/acceso, paste test tenant token
```

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(waapi): read-only client portal with token session login"
```

---

### Task 10: Deploy waapi.lebytek.com on VPS

**Files:**
- Modify: `Lebytek_Framework/.env.example` (add `WAAPI_PORTAL_ENABLED=true`)
- Modify: `WhatsApiLebytek/docs/integration/VPS_CHECKLIST.md` § waapi

- [ ] **Step 1: CloudPanel site setup**

Deploy Framework tree to waapi vhost; `.env`:

```env
WAAPI_PORTAL_ENABLED=true
LEBYTEK_API_URL=https://api.lebytek.com/api/v1
# NO LEBYTEK_API_TOKEN needed for portal (clients use tenant tokens)
MKT_EMAIL_DOCS_URL=https://docs.lebytek.com
```

- [ ] **Step 2: Smoke HTTPS**

```bash
curl -sfI https://waapi.lebytek.com/ | head -1
curl -sfI https://waapi.lebytek.com/portal/acceso | head -1
```

- [ ] **Step 3: Login smoke with demo token from CRUD provision**

Expected: dashboard shows instance status; QR page renders for `waiting_qr`.

- [ ] **Step 4: Update VPS_CHECKLIST with dated evidence**

- [ ] **Step 5: Commit checklist (api repo)**

---

### Task 11: Email dashboard CTA (`MKT_EMAIL_DASHBOARD_URL`)

**Files:**
- Modify: `Lebytek_Framework/app/Application/Marketing/LeadApiProvisioningService.php`
- Modify: `Lebytek_Framework/app/Presentation/Views/emails/lead_api_credentials.php`
- Modify: `Lebytek_Framework/.env.example`
- Test: `Lebytek_Framework/tests/Integration/LeadApiProvisioningServiceTest.php`

- [ ] **Step 1: Extend test — when dashboard URL set, HTML contains waapi link**

```php
// In test setup, set EnvLoader mock or putenv MKT_EMAIL_DASHBOARD_URL
assert_true(str_contains($mailer->last->html, 'waapi.lebytek.com/portal/acceso'));
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Update service + template**

In `sendCredentialsEmail()`:

```php
$dashboardUrl = rtrim((string) EnvLoader::get('MKT_EMAIL_DASHBOARD_URL', ''), '/');
$html = ViewHelper::render('emails/lead_api_credentials', [
    // ...existing...
    'dashboardUrl' => $dashboardUrl,
    'showDashboardCta' => $dashboardUrl !== '',
], '');
```

In `lead_api_credentials.php` after docs CTA:

```php
<?php if (! empty($showDashboardCta) && ! empty($dashboardUrl)): ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
        <td align="center">
            <?= $renderPartial('_cta_button', [
                'url'   => $dashboardUrl,
                'label' => 'Acceder a tu panel',
            ]) ?>
        </td>
    </tr>
</table>
<?php endif; ?>
```

- [ ] **Step 4: Set prod env**

```env
MKT_EMAIL_DASHBOARD_URL=https://waapi.lebytek.com/portal/acceso
```

- [ ] **Step 5: Commit + run tests**

---

### Task 12: `PUT /credentials/green-api` 501 stub

**Files:**
- Create: `app/Http/Controllers/Api/V1/CredentialsController.php`
- Modify: `routes/api.php`
- Modify: `docs/integration/waapi-api-contract.md`
- Test: `tests/Feature/Api/CredentialsStubTest.php`

- [ ] **Step 1: Failing test**

```php
test('put credentials returns 501 not implemented', function () {
    $token = platformServiceToken();
    $this->withToken($token)
        ->putJson(route('api.v1.credentials.green-api'), [
            'instanceId' => '110100',
            'apiTokenInstance' => 'secret',
        ], idempotencyHeaders())
        ->assertStatus(501)
        ->assertJsonPath('message', 'Not implemented');
});
```

- [ ] **Step 2–4: Implement controller returning 501; document definitively in contract**

- [ ] **Step 5: Commit**

---

### Task 13: docs.lebytek.com sync + portal guide

**Files:**
- Create: `docs.lebytek.com/api/content/guides/portal-cliente-waapi.md`
- Modify: `docs.lebytek.com/scripts/sync-docs.mjs`
- Modify: `docs.lebytek.com/api/_sidebar.md`
- Modify: `WhatsApiLebytek/docs/integration/waapi-api-contract.md` (move `/messages` to implemented)

- [ ] **Step 1: Write portal guide** (short: token login flow, routes, no Green token)

- [ ] **Step 2: Add to sync script**

```javascript
const API_FILES = [
  // ...existing...
  'guides/portal-cliente-waapi.md',
];
```

Also copy from `WhatsApiLebytek/docs/guides/portal-cliente-waapi.md` (create source in api repo first).

- [ ] **Step 3: Run sync**

```bash
cd docs.lebytek.com
node scripts/sync-docs.mjs
```

- [ ] **Step 4: Deploy nginx** per `nginx-docs.lebytek.com.conf`; verify `https://docs.lebytek.com/api/content/integration/waapi-api-contract.md`

- [ ] **Step 5: Commit all three repos**

---

### Task 14: VPS crons (health + expire demos)

**Files:**
- Reference: `Lebytek_Framework/scripts/lebytek-api-health.php`
- Reference: `Lebytek_Framework/scripts/expire-api-demos.php`
- Modify: `WhatsApiLebytek/docs/integration/VPS_CHECKLIST.md`

- [ ] **Step 1: Install crontab for user `lebytek`**

```bash
ssh lebytek-vps
sudo crontab -u lebytek -e
```

Add:

```cron
*/5 * * * * cd /home/lebytek/htdocs/lebytek.com && php scripts/lebytek-api-health.php >> /home/lebytek/logs/api-health.log 2>&1
0 3 * * * cd /home/lebytek/htdocs/lebytek.com && php scripts/expire-api-demos.php 30 >> /home/lebytek/logs/expire-demos.log 2>&1
```

- [ ] **Step 2: Verify one manual run**

```bash
sudo -u lebytek php /home/lebytek/htdocs/lebytek.com/scripts/lebytek-api-health.php; echo exit:$?
```

Expected: `exit:0`

- [ ] **Step 3: Document crontab output in VPS_CHECKLIST with date**

- [ ] **Step 4: Commit**

---

### Task 15: Observability + production security (api)

**Files:**
- Modify: `WhatsApiLebytek/routes/auth.php`
- Modify: `WhatsApiLebytek/.env.example`
- Modify: `config/horizon.php` (verify mail/slack from env)
- Ops: api VPS `.env`

- [ ] **Step 1: Disable public register in production**

In `routes/auth.php`:

```php
if (app()->environment('production')) {
    Route::redirect('register', '/admin/login');
} else {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);
}
```

Update `tests/Feature/Auth/RegistrationTest.php` to skip or use `APP_ENV=local`.

- [ ] **Step 2: Configure Sentry on api VPS**

```env
SENTRY_LARAVEL_DSN=https://...@sentry.io/...
SESSION_SECURE_COOKIE=true
```

Trigger test exception; confirm event in Sentry dashboard.

- [ ] **Step 3: Horizon alerts**

```env
HORIZON_MAIL=ops@lebytek.com
HORIZON_SLACK_WEBHOOK=https://hooks.slack.com/...
```

- [ ] **Step 4: Commit code changes**

---

### Task 16: Go-live — merge Framework main + DNS cutover

**Files:**
- Ops: GitHub PR `feature/backoffice-api-integration` → `main`
- Tag: `v1.0.0`
- Modify: `docs/integration/VPS_CHECKLIST.md`

**Prerequisites:** Task 7 smoke message green; Task 10 waapi live; Task 13 docs live.

- [ ] **Step 1: Final test gate**

```bash
cd Lebytek_Framework && php tests/run.php
cd ../WhatsApiLebytek && php artisan test
```

Expected: all green.

- [ ] **Step 2: Merge PR + tag**

```bash
gh pr create --title "Integration phase 4/5 go-live" --body "..."
gh pr merge --merge
git tag v1.0.0 && git push origin v1.0.0
```

- [ ] **Step 3: Deploy lebytek.com from main**

```bash
ssh lebytek-vps
cd /home/lebytek/htdocs/lebytek.com
git fetch && git checkout main && git pull
composer install --no-dev
bash scripts/vps-deploy-lebytek-com.sh
```

- [ ] **Step 4: DNS cutover** (operator)

Lower TTL 24h before; point A record `lebytek.com` → VPS IP.

- [ ] **Step 5: Post-cutover smoke** (spec §5.2 + waapi portal login)

- [ ] **Step 6: Document 7-day stability window in VPS_CHECKLIST**

---

### Task 17: Campaign MVP (optional — P2 same sprint)

**Files:**
- Create: migration `dom_campanias` + `dom_campania_destinatarios`
- Create: `CampaignController`, `CampaignService`
- Modify: `app/Jobs/CampaignBatchJob.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/CampaignDispatchTest.php`

Skip if timeboxed; spec allows post-messages. If implemented, follow spec §4.3–4.4 from `2026-07-01-integration-phase2-3-design.md` verbatim.

- [ ] **Step 1–5:** TDD cycle per Message tasks pattern.

---

### Task 18: Legacy Green cleanup + PLATFORM rename

**Files:**
- Modify: `Lebytek_Framework/src/Presentation/Controllers/Admin/IntegrationsController.php` (archive UI post-cutover)
- Modify: `WhatsApiLebytek/config/services.php` — ensure `PLATFORM_SERVICE_EMAIL` primary, `WAAPI_SERVICE_EMAIL` alias
- Modify: `docs/integration/lebytek-implementation-real.md` § legacy removed

**Prerequisites:** 30 days post-cutover with `GREEN_API_ENABLED=false` (or expedited if approved).

- [ ] **Step 1: grep legacy Green in Framework app**

```bash
grep -r "green-api" Lebytek_Framework/app Lebytek_Framework/src --include="*.php" | grep -v test
```

- [ ] **Step 2: Remove or guard unused legacy provisioning UI**

- [ ] **Step 3: Verify env alias**

```bash
grep -E "PLATFORM_SERVICE|WAAPI_SERVICE" WhatsApiLebytek/.env.example
```

Both documented; code reads `PLATFORM_SERVICE_EMAIL` with fallback to `WAAPI_SERVICE_EMAIL`.

- [ ] **Step 4: Commit + document**

---

### Task 19: Final audit + honest checklists

**Files:**
- Modify: `docs/superpowers/specs/2026-07-02-integration-phase4-5-design.md` §0 table
- Modify: `docs/integration/VPS_CHECKLIST.md`
- Modify: `docs/integration/README.md` or `ARCHITECTURE.md`
- Modify: `docs/integration/role-delegation-lebytek-api.md` checkboxes

- [ ] **Step 1: Run continuous review checklist** (spec §6)

```bash
php tests/run.php          # Framework
php artisan test           # api
# diff routes vs contract
php artisan route:list --path=api/v1 > /tmp/routes.txt
```

- [ ] **Step 2: Update §0 audit table** — mark `/messages`, waapi portal, crons, DNS with dates.

- [ ] **Step 3: Add "Integración completa" section** to `docs/ARCHITECTURE.md` summarizing phases 0–5.

- [ ] **Step 4: Commit specs + plans to git** (resolve untracked specs from §0 ⚠️)

```bash
git add docs/superpowers/specs/ docs/superpowers/plans/
git commit -m "docs: close integration phase 4/5 with final audit"
```

---

## Self-review (plan author checklist)

| Spec requirement | Task |
|------------------|------|
| 4.1 Reactivar waapi VPS | Task 10 |
| 4.2 Login token sesión server-side | Task 8 |
| 4.3 Dashboard estado instancia | Task 9 |
| 4.4 Vista QR proxy api | Task 9 |
| 4.5 Resumen uso | Task 9 (placeholder OK) |
| 4.6 Correo dashboard URL | Task 11 |
| 4.7 Landing waapi | Task 9 |
| 4.8 Tests sin Green directo | Task 8–9 |
| 5.1 `/messages` carry-over | Tasks 1–7 |
| 5.2 DNS + merge main | Task 16 |
| 5.3 docs.lebytek.com | Task 13 |
| 5.4 Crons | Task 14 |
| 5.5 Sentry + Horizon | Task 15 |
| 5.6 TenantUsageService hook | Task 4 |
| 5.7 Seguridad prod register | Task 15 |
| 5.8 credentials 501 | Task 12 |
| 5.9 Legacy cleanup | Task 18 |
| 5.10 PLATFORM rename | Task 18 |
| 5.11 Auditoría final | Task 19 |
| Campañas MVP (P2) | Task 17 (optional) |
| int_webhooks completo | Task 5 |

**Placeholder scan:** No TBD/TODO/fill-in-later steps remain.

**Type consistency:** `Mensaje.public_id`, `TransactionalMessageJob($mensajePublicId)`, `MessageResource.publicId`, route param `{mensaje:public_id}` aligned throughout.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-02-integration-phase4-5.md`. Two execution options:

**1. Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?
