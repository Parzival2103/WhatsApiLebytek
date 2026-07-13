# WhatsApp Group Recipient — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow `POST /api/v1/messages` to accept WhatsApp group chat ids (`…@g.us`) in `recipient` without breaking existing E.164 phone sends, and document the dual format for end clients without naming the WhatsApp provider vendor.

**Architecture:** Extract recipient rules into `RecipientNormalizer`. `StoreMessageRequest` validates by calling the normalizer. `MessageSendService` persists the normalized value. Existing `InstanceClient::sendMessage` already passes through any string containing `@`; no send-client API change. Update API contract + `docsV2` sandbox/docs in client-facing language only.

**Tech Stack:** Laravel (WhatsApiLebytek), Pest, Sanctum, React/TS (`docsV2`)

**Spec:** [`docs/superpowers/specs/2026-07-13-whatsapp-group-recipient-design.md`](../specs/2026-07-13-whatsapp-group-recipient-design.md)

## Global constraints

- **Backward compatible:** any current valid E.164 payload must behave identically.
- **Group format only:** full `digits@g.us` — do **not** invent `@g.us` from bare digits.
- **No new fields / endpoints / migrations.**
- **Client docs:** never mention the WhatsApp provider vendor name (use “WhatsApp”, “Lebytek API”, “ID de grupo”).
- **No deploy / VPS / merge to Framework `main`** unless the user explicitly orders it.
- **TDD:** write failing tests before implementation in each code task.
- **Repos commits:** WhatsApiLebytek and docsV2 are separate git repos — commit in each separately.

---

## File map

| File | Action | Responsibility |
|------|--------|----------------|
| `app/Services/Messaging/RecipientNormalizer.php` | **Create** | Normalize phone (digits) or pass-through `@g.us` |
| `tests/Unit/Messaging/RecipientNormalizerTest.php` | **Create** | Unit coverage for both formats + rejects |
| `app/Http/Requests/Api/V1/StoreMessageRequest.php` | Modify | `max:48` + rule via normalizer |
| `app/Services/Messaging/MessageSendService.php` | Modify | Use normalizer instead of `preg_replace` |
| `tests/Feature/Api/MessageSendTest.php` | Modify | Group accept, invalid 422 |
| `tests/Unit/Queue/TransactionalMessageJobTest.php` | Modify | Assert group chatId reaches HTTP fake |
| `tests/Unit/GreenApi/InstanceClientSendMessageTest.php` | Modify | Optional assert `@g.us` pass-through |
| `docs/integration/waapi-api-contract.md` | Modify | Dual `recipient` formats (client language) |
| `Lebytek_Framework/docs/integration/waapi-api-contract.md` | Modify | Mirror recipient section (same wording) |
| `docsV2/src/lib/lebytekApi.ts` | Modify | `validateRecipient` accepts phone or group |
| `docsV2/src/data.ts` | Modify | Mensajes docs: formatos + ejemplo grupo |
| `docsV2/src/components/Sandbox/DemoSandbox.tsx` | Modify | Input accepts `@g.us`; update hints |
| `docsV2/public/tester.php` | Modify | Commented group example (optional) |

---

### Task 1: `RecipientNormalizer` (TDD)

**Repo:** `WhatsApiLebytek`

**Files:**
- Create: `tests/Unit/Messaging/RecipientNormalizerTest.php`
- Create: `app/Services/Messaging/RecipientNormalizer.php`

- [ ] **Step 1: Write the failing unit tests**

Create `tests/Unit/Messaging/RecipientNormalizerTest.php`:

```php
<?php

use App\Services\Messaging\RecipientNormalizer;
use InvalidArgumentException;

test('normalizes e164 digits and strips non-digits', function () {
    $n = new RecipientNormalizer;

    expect($n->normalize('5215512345678'))->toBe('5215512345678')
        ->and($n->normalize('+52 155 1234 5678'))->toBe('5215512345678');
});

test('passes through whatsapp group chat id', function () {
    $n = new RecipientNormalizer;
    $group = '120363012345678901@g.us';

    expect($n->normalize($group))->toBe($group)
        ->and($n->normalize('  '.$group.'  '))->toBe($group);
});

test('rejects invalid recipients', function (string $raw) {
    $n = new RecipientNormalizer;
    expect(fn () => $n->normalize($raw))->toThrow(InvalidArgumentException::class);
})->with([
    '',
    'foo',
    '123@g.us',
    '120363012345678901@c.us',
    '120363012345678901@G.US',
    '@g.us',
    '52',
]);
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
php artisan test tests/Unit/Messaging/RecipientNormalizerTest.php
```

Expected: FAIL — class `RecipientNormalizer` not found.

- [ ] **Step 3: Implement `RecipientNormalizer`**

Create `app/Services/Messaging/RecipientNormalizer.php`:

```php
<?php

namespace App\Services\Messaging;

use InvalidArgumentException;

class RecipientNormalizer
{
    private const GROUP_PATTERN = '/^\d{10,32}@g\.us$/';

    public function normalize(string $raw): string
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Recipient is required.');
        }

        if (str_contains($trimmed, '@')) {
            if (preg_match(self::GROUP_PATTERN, $trimmed) !== 1) {
                throw new InvalidArgumentException('Invalid group recipient.');
            }

            return $trimmed;
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if (strlen($digits) < 10 || strlen($digits) > 15) {
            throw new InvalidArgumentException('Invalid phone recipient.');
        }

        return $digits;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run:

```bash
php artisan test tests/Unit/Messaging/RecipientNormalizerTest.php
```

Expected: PASS (all examples).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Messaging/RecipientNormalizer.php tests/Unit/Messaging/RecipientNormalizerTest.php
git commit -m "feat(api): add RecipientNormalizer for phone and group chat ids"
```

---

### Task 2: Wire request + `MessageSendService`

**Repo:** `WhatsApiLebytek`

**Files:**
- Modify: `app/Http/Requests/Api/V1/StoreMessageRequest.php`
- Modify: `app/Services/Messaging/MessageSendService.php`
- Modify: `tests/Feature/Api/MessageSendTest.php`

- [ ] **Step 1: Add failing feature tests**

Append to `tests/Feature/Api/MessageSendTest.php`:

```php
test('tenant token can POST message to group recipient', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create(['tenant_id' => $tenant->id, 'module_key' => 'whatsapp', 'is_enabled' => true]);
    $instancia = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo(['mensajes.enviar', 'mensajes.ver']);
    $token = $client->createToken('client', ['mensajes.enviar', 'mensajes.ver'])->plainTextToken;

    $group = '120363012345678901@g.us';

    $response = $this->withToken($token)
        ->postJson(route('api.v1.messages.store'), [
            'recipient' => $group,
            'body' => 'Hola grupo',
            'instancePublicId' => $instancia->public_id,
        ], idempotencyHeaders());

    $response->assertAccepted()
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('recipient', $group);

    expect(Mensaje::query()->where('tenant_id', $tenant->id)->value('recipient'))->toBe($group);
    Bus::assertDispatched(TransactionalMessageJob::class);
});

test('POST messages rejects invalid group recipient with 422', function () {
    $tenant = Tenant::factory()->create();
    Module::factory()->create(['tenant_id' => $tenant->id, 'module_key' => 'whatsapp', 'is_enabled' => true]);
    $instancia = Instancia::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'authorized',
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo('mensajes.enviar');
    $token = $client->createToken('client', ['mensajes.enviar'])->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.messages.store'), [
            'recipient' => '120363012345678901@c.us',
            'body' => 'No',
            'instancePublicId' => $instancia->public_id,
        ], idempotencyHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['recipient']);
});
```

- [ ] **Step 2: Run feature tests — expect failure on group path**

Run:

```bash
php artisan test --filter="tenant token can POST message to group recipient|POST messages rejects invalid group recipient"
```

Expected: group test FAIL (recipient stored as digits-only / wrong) or 422; invalid test may already 422 for other reasons — confirm group happy-path fails until service is fixed.

- [ ] **Step 3: Update `StoreMessageRequest`**

Replace `rules()` in `app/Http/Requests/Api/V1/StoreMessageRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Messaging\RecipientNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

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
            'recipient' => ['required', 'string', 'max:48'],
            'body' => ['required', 'string', 'max:4096'],
            'instancePublicId' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $raw = (string) $this->input('recipient', '');

            try {
                app(RecipientNormalizer::class)->normalize($raw);
            } catch (InvalidArgumentException) {
                $validator->errors()->add(
                    'recipient',
                    'The recipient must be an E.164 phone number (digits) or a WhatsApp group id ending in @g.us.',
                );
            }
        });
    }
}
```

Keep the validation message free of vendor names.

- [ ] **Step 4: Update `MessageSendService`**

In `app/Services/Messaging/MessageSendService.php`:

1. Import `RecipientNormalizer`.
2. Inject it in the constructor (or resolve via `app()` once — prefer constructor DI to match existing style).
3. Replace:

```php
$normalizedRecipient = preg_replace('/\D+/', '', $recipient) ?? $recipient;
```

with:

```php
$normalizedRecipient = $this->recipientNormalizer->normalize($recipient);
```

Full constructor should become:

```php
public function __construct(
    private readonly WhatsappModuleGuard $moduleGuard,
    private readonly InstanceStateSyncService $stateSync,
    private readonly AccountStatusService $accountStatusService,
    private readonly RecipientNormalizer $recipientNormalizer,
) {}
```

Laravel auto-wires the concrete class — no container binding required.

- [ ] **Step 5: Run full message feature suite**

Run:

```bash
php artisan test tests/Feature/Api/MessageSendTest.php
```

Expected: PASS (including existing phone + new group tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/Api/V1/StoreMessageRequest.php app/Services/Messaging/MessageSendService.php tests/Feature/Api/MessageSendTest.php
git commit -m "feat(api): accept WhatsApp group ids in POST /messages recipient"
```

---

### Task 3: Job + `InstanceClient` pass-through coverage

**Repo:** `WhatsApiLebytek`

**Files:**
- Modify: `tests/Unit/Queue/TransactionalMessageJobTest.php`
- Modify: `tests/Unit/GreenApi/InstanceClientSendMessageTest.php`

- [ ] **Step 1: Add job test for group chatId**

Append to `tests/Unit/Queue/TransactionalMessageJobTest.php`:

```php
test('transactional job sends group recipient as chatId without rewriting', function () {
    Http::fake([
        '*sendMessage*' => Http::response(['idMessage' => 'GA-GROUP-1'], 200),
    ]);

    $instancia = Instancia::factory()->create([
        'status' => 'authorized',
        'id_instance' => '1101000001',
        'api_token_instance' => 'tok',
    ]);
    $group = '120363012345678901@g.us';
    $mensaje = Mensaje::factory()->create([
        'tenant_id' => $instancia->tenant_id,
        'instancia_id' => $instancia->id,
        'status' => 'queued',
        'recipient' => $group,
        'body' => 'Grupo',
    ]);

    (new TransactionalMessageJob($mensaje->id))->handle();

    $mensaje->refresh();
    expect($mensaje->status)->toBe('sent')
        ->and($mensaje->green_message_id)->toBe('GA-GROUP-1');

    Http::assertSent(function ($request) use ($group) {
        return str_contains($request->url(), 'sendMessage')
            && ($request['chatId'] ?? null) === $group
            && ($request['message'] ?? null) === 'Grupo';
    });
});
```

- [ ] **Step 2: Add InstanceClient unit case**

Append to `tests/Unit/GreenApi/InstanceClientSendMessageTest.php`:

```php
test('sendMessage passes through group chatId', function () {
    Http::fake([
        '*sendMessage*' => Http::response(['idMessage' => 'MSG-G'], 200),
    ]);

    $client = new \App\Services\GreenApi\InstanceClient(
        'https://api.green-api.com',
        '1101000001',
        'secret-token',
    );

    $group = '120363012345678901@g.us';
    $id = $client->sendMessage($group, 'Hola grupo');

    expect($id)->toBe('MSG-G');

    Http::assertSent(function ($request) use ($group) {
        return ($request['chatId'] ?? null) === $group;
    });
});
```

(If the existing test file constructs the client differently, match that constructor call pattern exactly.)

- [ ] **Step 3: Run unit tests**

Run:

```bash
php artisan test tests/Unit/Queue/TransactionalMessageJobTest.php tests/Unit/GreenApi/InstanceClientSendMessageTest.php
```

Expected: PASS. If group job fails because `InstanceClient` rewrites incorrectly, fix only the rewrite bug — current code already keeps `@`.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/Queue/TransactionalMessageJobTest.php tests/Unit/GreenApi/InstanceClientSendMessageTest.php
git commit -m "test(api): cover group chatId path through job and InstanceClient"
```

---

### Task 4: API contract docs (WhatsApiLebytek + Framework mirror)

**Repos:** `WhatsApiLebytek`, `Lebytek_Framework`

**Files:**
- Modify: `WhatsApiLebytek/docs/integration/waapi-api-contract.md`
- Modify: `Lebytek_Framework/docs/integration/waapi-api-contract.md`

- [ ] **Step 1: Update WhatsApiLebytek contract `POST /messages` section**

In `docs/integration/waapi-api-contract.md`, under `### POST /messages`, replace the body example block and add formats:

```markdown
**Body:**

```json
{
  "recipient": "5215512345678",
  "body": "Hola desde Lebytek API",
  "instancePublicId": "01JINST..."
}
```

**`recipient` formats (required):**

| Tipo | Ejemplo | Notas |
|------|---------|--------|
| Teléfono E.164 sin `+` | `5215512345678` | Solo dígitos; la API ignora espacios/`+` de entrada |
| ID de grupo WhatsApp | `120363012345678901@g.us` | ChatId completo; no enviar solo los dígitos del grupo |

Ejemplo grupo:

```json
{
  "recipient": "120363012345678901@g.us",
  "body": "Aviso para el grupo",
  "instancePublicId": "01JINST..."
}
```
```

Also update any line that says `recipient` is only a phone. Prefer “**Sin** tokens del proveedor” if rewriting the “Sin token Green” note while editing the nearby `GET /messages` sentence — keep security intent, client-safe wording.

- [ ] **Step 2: Mirror the same `recipient` section in Framework**

Apply the same `recipient` formats + group example to `Lebytek_Framework/docs/integration/waapi-api-contract.md` under `POST /messages`.

- [ ] **Step 3: Commit each repo**

WhatsApiLebytek:

```bash
git add docs/integration/waapi-api-contract.md
git commit -m "docs(contract): document phone and group recipient formats for POST /messages"
```

Lebytek_Framework (on current integration branch — do **not** merge to `main`):

```bash
git add docs/integration/waapi-api-contract.md
git commit -m "docs(contract): mirror group recipient formats for POST /messages"
```

---

### Task 5: docsV2 — `validateRecipient` + Mensajes docs

**Repo:** `docsV2`

**Files:**
- Modify: `src/lib/lebytekApi.ts`
- Modify: `src/data.ts`

- [ ] **Step 1: Update `validateRecipient`**

In `src/lib/lebytekApi.ts`, replace constants/helpers as follows:

```typescript
const BODY_MAX = 1000;
const PHONE_MIN = 10;
const PHONE_MAX = 15;
const RECIPIENT_MAX = 48;
const GROUP_RECIPIENT = /^\d{10,32}@g\.us$/;

export function validateRecipient(recipient: string): string {
  const trimmed = recipient.trim();
  if (trimmed.length < 1 || trimmed.length > RECIPIENT_MAX) {
    throw new Error('Destinatario inválido.');
  }

  if (trimmed.includes('@')) {
    if (!GROUP_RECIPIENT.test(trimmed)) {
      throw new Error('ID de grupo inválido. Usa el formato completo terminado en @g.us.');
    }
    return trimmed;
  }

  const digits = trimmed.replace(/\D/g, '');
  if (digits.length < PHONE_MIN || digits.length > PHONE_MAX) {
    throw new Error('Destinatario inválido. Usa E.164 sin + (10–15 dígitos) o un ID de grupo @g.us.');
  }
  return digits;
}
```

Do not mention the provider vendor in error strings.

- [ ] **Step 2: Update Mensajes markdown in `src/data.ts`**

In the `mensajes` entry (`title: "Mensajes"`), change the body table row and “Formato de destinatario” section to:

```markdown
| \`recipient\` | Requerido, string, máx. 48. Teléfono E.164 (dígitos) **o** ID de grupo WhatsApp (\`…@g.us\`) |

## Formato de destinatario

### Contacto (teléfono)

Para celulares en México, usa E.164 **sin** \`+\`:

| Formato | Ejemplo | ¿Válido? |
| :--- | :--- | :---: |
| Correcto — \`52\` + \`1\` + 10 dígitos | \`5215512345678\` | Sí |
| Incorrecto — \`528\` + … (falta el \`1\`) | \`5285512345678\` | No |

Regla: **52** (país) + **1** (móvil) + **10 dígitos** → \`521XXXXXXXXXX\`.

### Grupo de WhatsApp

Usa el **ID completo del grupo**, terminado en \`@g.us\` (no solo la parte numérica):

| Formato | Ejemplo | ¿Válido? |
| :--- | :--- | :---: |
| Correcto | \`120363012345678901@g.us\` | Sí |
| Incorrecto — sin \`@g.us\` | \`120363012345678901\` | No (se interpreta como teléfono) |

\`\`\`json
{
  "recipient": "120363012345678901@g.us",
  "body": "Aviso para el grupo",
  "instancePublicId": "01JXXXXXXXXXXXXXXXXXXXXXX"
}
\`\`\`
```

Keep the existing phone JSON example; place the group example after the group subsection. Scan the section for provider vendor names and remove them if introduced.

- [ ] **Step 3: Typecheck / build if available**

Run from `docsV2`:

```bash
npm run build
```

Expected: build succeeds (or project’s usual check). If there is no build script, run whatever `package.json` defines for `lint`/`typecheck`.

- [ ] **Step 4: Commit**

```bash
git add src/lib/lebytekApi.ts src/data.ts
git commit -m "docs: document group recipient format and validate @g.us in sandbox client"
```

---

### Task 6: docsV2 — sandbox UI + tester.php

**Repo:** `docsV2`

**Files:**
- Modify: `src/components/Sandbox/DemoSandbox.tsx`
- Modify: `public/tester.php`

- [ ] **Step 1: Update sandbox recipient input**

In `DemoSandbox.tsx` send step:

1. Change label to something like: `Destinatario (teléfono E.164 o ID de grupo @g.us)`.
2. Change input: `type="text"`, remove `inputMode="numeric"`, update placeholder to show both formats, e.g. `521… o …@g.us`.
3. Replace `onChange` that strips non-digits with:

```tsx
onChange={(e) => setRecipient(e.target.value)}
```

4. Replace disabled condition `recipient.length < 10` with a soft check that allows group:

```tsx
disabled={loading || recipient.trim().length < 10}
```

(Validation on send still goes through `validateRecipient`.)

5. Soften success copy if it says “Revisa WhatsApp en {number}” — for groups “Revisa el grupo …” is fine without naming vendor internals.

- [ ] **Step 2: Add commented group example in `tester.php`**

Near the default messages body example, add a short comment/alternative (PHP comment or HTML note) showing group JSON — still without naming the provider vendor.

Example comment near the preset body:

```php
// Alternativa grupo: "recipient": "120363012345678901@g.us"
```

- [ ] **Step 3: Build / smoke UI manually if possible**

Run:

```bash
npm run build
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add src/components/Sandbox/DemoSandbox.tsx public/tester.php
git commit -m "feat(docs): allow group chat ids in demo sandbox recipient field"
```

---

### Task 7: Final verification

**Repos:** `WhatsApiLebytek`, `docsV2`

- [ ] **Step 1: Run WhatsApiLebytek tests**

```bash
php artisan test tests/Unit/Messaging/RecipientNormalizerTest.php tests/Feature/Api/MessageSendTest.php tests/Unit/Queue/TransactionalMessageJobTest.php tests/Unit/GreenApi/InstanceClientSendMessageTest.php
```

Expected: all PASS.

- [ ] **Step 2: Optional full suite**

```bash
composer test
```

Expected: PASS (or only pre-existing failures unrelated to this change — do not ignore new failures from this work).

- [ ] **Step 3: Confirm docs language**

Search in docs artifacts for the provider vendor name and remove from client-facing copy:

```bash
# WhatsApiLebytek — only the client-facing sections you edited
rg -n "Green API|green-api|GreenApi" docs/integration/waapi-api-contract.md

# docsV2
rg -n "Green API|green-api|GreenApi" src/data.ts src/lib/lebytekApi.ts src/components/Sandbox/DemoSandbox.tsx public/tester.php
```

Expected for docsV2 files: **no matches**. Internal API namespaces (`App\Services\GreenApi\…`) in PHP code are fine and must remain.

- [ ] **Step 4: Acceptance checklist (spec §7)**

1. E.164 clients unchanged.
2. `…@g.us` enqueued and job uses that chatId.
3. Invalid → `422`.
4. Public docs/sandbox explain both formats without vendor naming.
5. Tests green.

- [ ] **Step 5: Stop — no deploy**

Do **not** SSH, pull on VPS, or push unless the user explicitly asks.

---

## Spec coverage self-check

| Spec item | Task |
|-----------|------|
| `RecipientNormalizer` phone + `@g.us` | Task 1 |
| `StoreMessageRequest` max 48 + validation | Task 2 |
| `MessageSendService` uses normalizer | Task 2 |
| `InstanceClient` unchanged / `@` pass-through | Task 3 |
| Feature tests group + 422 | Task 2 |
| Job chatId assertion | Task 3 |
| `waapi-api-contract.md` | Task 4 |
| Framework mirror | Task 4 |
| docsV2 `data.ts` + `lebytekApi.ts` | Task 5 |
| Sandbox + tester | Task 6 |
| No list-groups / no new endpoint / no deploy | Constraints + Task 7 |
| No vendor name in client docs | Tasks 4–7 |

## Placeholder / consistency self-check

- Method name: `normalize(string $raw): string` everywhere.
- Group example id: `120363012345678901@g.us` consistent across tests and docs.
- Pattern: `^\d{10,32}@g\.us$` in PHP and TS.
- Max length: `48` in request, TS `RECIPIENT_MAX`, and docs table.
