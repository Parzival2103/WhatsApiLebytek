# Green API Delay Send Messages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply Green API `delaySendMessagesMilliseconds: 15000` on every new instance at provision time, and provide a one-shot Artisan command to push the same setting to all existing eligible instances.

**Architecture:** Introduce a single named constant for the delay value. Extend `ProvisionGreenInstanceJob` `setSettings` with that constant. Add `green:apply-send-delay` (`--dry-run`) that iterates eligible `Instancia` rows and calls `InstanceClient::setSettings` with **only** the delay field (Green documents selective/`partial` updates). Document post-deploy ops including the Green **instance reboot** side effect.

**Tech Stack:** Laravel 13, Pest, `Illuminate\Support\Facades\Http`, `InstanceClient`, Horizon queue `provisioning` (unchanged).

**Spec:** [`docs/superpowers/specs/2026-07-23-green-api-delay-send-messages-design.md`](../specs/2026-07-23-green-api-delay-send-messages-design.md)

## Global Constraints

- Delay value is **fixed** `15000`, defined once as `GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS` (no env/config in v1). Job and command must both reference that constant — never duplicate the literal.
- Provisioning must keep existing webhook fields: `webhookUrl`, `webhookUrlToken`, `incomingWebhook`, `stateWebhook`.
- Command payload is **only** `delaySendMessagesMilliseconds` (do not rewrite webhook settings). Safe because Green `setSettings` allows selective parameters.
- Eligible instances: non-empty `id_instance`; non-null `api_token_instance` that decrypts to a non-empty string; `status` **not in** `deleted`, `deleting` (include `failed` / `provisioning` / `configuring` / `waiting_qr` / `authorized` when credentials exist).
- Do **not** filter `api_token_instance != ''` in SQL — the column is encrypted; empty-ciphertext checks are unreliable. Validate with `filled()` after Eloquent decrypt.
- Partial failures: continue; catch `\Throwable` (not only `GreenApiException`); exit `1` if any fail; dry-run exits `0` and sends no HTTP.
- Do not log or print `api_token_instance`.
- Ops: every successful `setSettings` **reboots** the Green instance; settings apply within ~5 minutes. Deploy docs must say this.
- No UI, no scheduler, no Laravel campaign pacing changes.

## File structure

| File | Responsibility |
|------|----------------|
| `app/Services/GreenApi/GreenApiInstanceSettings.php` | Single source of truth for delay ms constant |
| `app/Jobs/ProvisionGreenInstanceJob.php` | Add delay (via constant) to existing `setSettings` array |
| `app/Console/Commands/ApplyGreenSendDelayCommand.php` | One-shot apply / dry-run for existing instances |
| `tests/Unit/Jobs/ProvisionGreenInstanceJobResumeTest.php` | Assert `setSettings` body includes delay (extend existing test) |
| `tests/Feature/Console/ApplyGreenSendDelayCommandTest.php` | Dry-run, apply success, partial failure, eligibility (`failed` in, `deleting`/`deleted` out) |
| `docs/DEPLOY.md` | Post-deploy ops note + reboot / apply-window warning |
| `docs/superpowers/specs/2026-07-23-green-api-delay-send-messages-design.md` | Keep aligned with plan (eligibility, reboot, constant) |

## Deploy / ops risks (read before Task 3)

| Risk | Mitigation in this plan |
|------|-------------------------|
| Green reboots instance on every `setSettings` | DEPLOY.md warns; run one-shot in low-traffic window; dry-run first |
| Settings take up to ~5 min to apply | Document wait; do not expect instant delay on next send |
| Encrypted token SQL filters | PHP `filled()` after decrypt; SQL only `whereNotNull` |
| `deleting` rows still have credentials | Exclude `deleting` + `deleted` |
| Concurrent provision job vs one-shot | Both partial-update safe; after Task 2, provision payload includes delay so last writer still leaves delay set |
| Future `webhooks:rotate-secret --apply-green` | Also partial update (webhook fields only) — will **not** wipe delay |

---

### Task 1: Named delay constant

**Files:**
- Create: `app/Services/GreenApi/GreenApiInstanceSettings.php`
- Test: covered indirectly by Tasks 2–3 (constant value asserted via HTTP body)

**Interfaces:**
- Produces: `App\Services\GreenApi\GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS` (`int`, value `15000`)

- [ ] **Step 1: Create the constant class**

```php
<?php

namespace App\Services\GreenApi;

/**
 * Instance-level Green API setting defaults used by provisioning and ops commands.
 */
final class GreenApiInstanceSettings
{
    /** Green-recommended send delay (ms) to mitigate Yellow Card / spam pacing. */
    public const DELAY_SEND_MESSAGES_MILLISECONDS = 15000;
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/GreenApi/GreenApiInstanceSettings.php
git commit -m "$(cat <<'EOF'
feat(green): add GreenApiInstanceSettings delay constant

Single source of truth for delaySendMessagesMilliseconds before wiring provisioning and the one-shot command.
EOF
)"
```

---

### Task 2: Provisioning `setSettings` includes delay

**Files:**
- Modify: `app/Jobs/ProvisionGreenInstanceJob.php` (the `setSettings([...])` call)
- Modify: `tests/Unit/Jobs/ProvisionGreenInstanceJobResumeTest.php`
- Test: same test file

**Interfaces:**
- Consumes: `InstanceClient::setSettings(array $settings): void`, `GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS`
- Produces: provisioning payload includes `'delaySendMessagesMilliseconds' => 15000` alongside webhook fields

- [ ] **Step 1: Strengthen the failing assertion in the existing resume test**

In `tests/Unit/Jobs/ProvisionGreenInstanceJobResumeTest.php`, replace the weak `setSettings` assert with a body check (match existing Green HTTP assert style: array access on the request):

```php
use App\Services\GreenApi\GreenApiInstanceSettings;

// ... keep Http::fake and job handle as-is ...

Http::assertNotSent(fn ($request) => str_contains($request->url(), '/partner/createInstance/'));

Http::assertSent(function ($request) {
    if (! str_contains($request->url(), '/setSettings/')) {
        return false;
    }

    return ($request['delaySendMessagesMilliseconds'] ?? null) === GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS
        && array_key_exists('webhookUrl', $request->data())
        && array_key_exists('webhookUrlToken', $request->data())
        && ($request['incomingWebhook'] ?? null) === 'yes'
        && ($request['stateWebhook'] ?? null) === 'yes';
});
```

Keep status expectations (`waiting_qr`, `green_state`, `last_error`) unchanged.

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
php artisan test --filter="provision job resumes configuring when credentials already exist"
```

Expected: FAIL because `delaySendMessagesMilliseconds` is missing from the request body.

- [ ] **Step 3: Add delay to provisioning `setSettings`**

In `app/Jobs/ProvisionGreenInstanceJob.php`, add the import and change the `setSettings` call to:

```php
use App\Services\GreenApi\GreenApiInstanceSettings;
// ...
$client->setSettings([
    'webhookUrl' => config('services.green_api.webhook_url'),
    'webhookUrlToken' => config('services.green_api.webhook_secret'),
    'incomingWebhook' => 'yes',
    'stateWebhook' => 'yes',
    'delaySendMessagesMilliseconds' => GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS,
]);
```

- [ ] **Step 4: Run test to verify it passes**

Run:

```bash
php artisan test --filter="provision job resumes configuring when credentials already exist"
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ProvisionGreenInstanceJob.php tests/Unit/Jobs/ProvisionGreenInstanceJobResumeTest.php
git commit -m "$(cat <<'EOF'
feat(green): set delaySendMessagesMilliseconds on provision

Apply Green API's recommended 15s send delay when configuring new instances to reduce Yellow Card risk.
EOF
)"
```

---

### Task 3: Artisan `green:apply-send-delay`

**Files:**
- Create: `app/Console/Commands/ApplyGreenSendDelayCommand.php`
- Create: `tests/Feature/Console/ApplyGreenSendDelayCommandTest.php`
- Test: `tests/Feature/Console/ApplyGreenSendDelayCommandTest.php`

**Interfaces:**
- Consumes: `InstanceClient::__construct(string $baseUrl, string $idInstance, string $apiTokenInstance)`, `InstanceClient::setSettings(array $settings): void`, `GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS`, `Instancia` model (encrypted `api_token_instance` cast)
- Produces: CLI `green:apply-send-delay {--dry-run}` returning `Command::SUCCESS` (0) or `Command::FAILURE` (1)

- [ ] **Step 1: Write the failing Feature tests**

Create `tests/Feature/Console/ApplyGreenSendDelayCommandTest.php`:

```php
<?php

use App\Models\Integration\Instancia;
use App\Services\GreenApi\GreenApiInstanceSettings;
use Illuminate\Support\Facades\Http;

test('dry-run lists eligible instances without calling Green', function () {
    Http::fake();

    Instancia::factory()->authorized()->create([
        'id_instance' => '1101000001',
        'api_token_instance' => 'token-ok',
    ]);

    Instancia::factory()->create([
        'status' => 'deleted',
        'id_instance' => '1101000002',
        'api_token_instance' => 'token-deleted',
    ]);

    Instancia::factory()->create([
        'status' => 'deleting',
        'id_instance' => '1101000003',
        'api_token_instance' => 'token-deleting',
    ]);

    Instancia::factory()->create([
        'status' => 'provisioning',
        'id_instance' => null,
        'api_token_instance' => null,
    ]);

    $this->artisan('green:apply-send-delay', ['--dry-run' => true])
        ->expectsOutputToContain('1 eligible')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('apply pushes delay setting to eligible instances', function () {
    Http::fake([
        '*/waInstance1102000001/setSettings/*' => Http::response(['saveSettings' => true], 200),
    ]);

    Instancia::factory()->authorized()->create([
        'id_instance' => '1102000001',
        'api_token_instance' => 'token-apply',
    ]);

    $this->artisan('green:apply-send-delay')
        ->assertExitCode(0);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/waInstance1102000001/setSettings/')) {
            return false;
        }

        $data = $request->data();

        return ($request['delaySendMessagesMilliseconds'] ?? null) === GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS
            && ! array_key_exists('webhookUrl', $data)
            && ! array_key_exists('webhookUrlToken', $data);
    });
});

test('apply continues on partial failure and exits non-zero', function () {
    Http::fake([
        '*/waInstance1103000001/setSettings/*' => Http::response(['error' => 'boom'], 500),
        '*/waInstance1103000002/setSettings/*' => Http::response(['saveSettings' => true], 200),
    ]);

    $failing = Instancia::factory()->authorized()->create([
        'id_instance' => '1103000001',
        'api_token_instance' => 'token-fail',
    ]);

    Instancia::factory()->authorized()->create([
        'id_instance' => '1103000002',
        'api_token_instance' => 'token-ok',
    ]);

    $this->artisan('green:apply-send-delay')
        ->expectsOutputToContain((string) $failing->id)
        ->assertExitCode(1);

    Http::assertSentCount(2);
});

test('apply includes failed status when credentials exist', function () {
    Http::fake([
        '*/waInstance1104000001/setSettings/*' => Http::response(['saveSettings' => true], 200),
    ]);

    Instancia::factory()->create([
        'status' => 'failed',
        'id_instance' => '1104000001',
        'api_token_instance' => 'token-failed-but-live',
    ]);

    $this->artisan('green:apply-send-delay')
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/waInstance1104000001/setSettings/'));
});

test('apply skips deleting status even with credentials', function () {
    Http::fake();

    Instancia::factory()->create([
        'status' => 'deleting',
        'id_instance' => '1105000001',
        'api_token_instance' => 'token-teardown',
    ]);

    $this->artisan('green:apply-send-delay')
        ->expectsOutputToContain('No eligible')
        ->assertExitCode(0);

    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
php artisan test tests/Feature/Console/ApplyGreenSendDelayCommandTest.php
```

Expected: FAIL (command `green:apply-send-delay` not registered / class missing).

- [ ] **Step 3: Implement the command**

Create `app/Console/Commands/ApplyGreenSendDelayCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Integration\Instancia;
use App\Services\GreenApi\GreenApiInstanceSettings;
use App\Services\GreenApi\InstanceClient;
use Illuminate\Console\Command;
use Throwable;

class ApplyGreenSendDelayCommand extends Command
{
    protected $signature = 'green:apply-send-delay
                            {--dry-run : List eligible instances without calling Green API}';

    protected $description = 'Apply delaySendMessagesMilliseconds=15000 to all eligible Green instances';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $baseUrl = (string) config('services.green_api.base_url');

        $candidates = Instancia::query()
            ->withoutGlobalScope('tenant')
            ->whereNotIn('status', ['deleted', 'deleting'])
            ->whereNotNull('id_instance')
            ->where('id_instance', '!=', '')
            ->whereNotNull('api_token_instance')
            ->orderBy('id')
            ->get();

        // Decrypt + filled() in PHP: encrypted column cannot be compared to '' reliably in SQL.
        $instances = $candidates->filter(function (Instancia $instancia): bool {
            return filled($instancia->id_instance) && filled($instancia->api_token_instance);
        })->values();

        if ($instances->isEmpty()) {
            $this->info('No eligible instances.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info($instances->count().' eligible instance(s) (dry-run, no HTTP).');
            foreach ($instances as $instancia) {
                $this->line('id='.$instancia->id.' id_instance='.$instancia->id_instance.' status='.$instancia->status);
            }

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($instances as $instancia) {
            $idInstance = (string) $instancia->id_instance;
            $token = (string) $instancia->api_token_instance;

            try {
                $client = new InstanceClient($baseUrl, $idInstance, $token);
                $client->setSettings([
                    'delaySendMessagesMilliseconds' => GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS,
                ]);
                $ok++;
                $this->line('OK instancia id='.$instancia->id);
            } catch (Throwable $e) {
                $failed++;
                $this->error('FAIL instancia id='.$instancia->id.': '.$e->getMessage());
            }
        }

        $this->info("Done. ok={$ok} failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
```

Laravel 13 auto-discovers commands under `app/Console/Commands`; no manual registration needed.

Notes for the implementer:
- Reading `$instancia->api_token_instance` yields plaintext via the encrypted cast. **Never** print it.
- Catch `\Throwable` so HTTP timeouts / unexpected errors still count as failures and do not abort the loop.
- SoftDeletes already hides trashed rows; excluding `deleted`/`deleting` covers in-progress teardown before `deleted_at` is set.

- [ ] **Step 4: Run tests to verify they pass**

Run:

```bash
php artisan test tests/Feature/Console/ApplyGreenSendDelayCommandTest.php
```

Expected: PASS (all 5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/ApplyGreenSendDelayCommand.php tests/Feature/Console/ApplyGreenSendDelayCommandTest.php
git commit -m "$(cat <<'EOF'
feat(green): add green:apply-send-delay for existing instances

One-shot Artisan command with --dry-run to push the 15s send delay to all eligible Green instances after Yellow Card guidance.
EOF
)"
```

---

### Task 4: Deploy docs + spec alignment

**Files:**
- Modify: `docs/DEPLOY.md` (section "Smoke tests post-deploy")
- Modify: `docs/superpowers/specs/2026-07-23-green-api-delay-send-messages-design.md` (eligibility, reboot, constant)

**Interfaces:**
- Consumes: command signature from Task 3
- Produces: ops-readable post-deploy steps that will not surprise operators when Green reboots instances

- [ ] **Step 1: Add ops note to DEPLOY.md**

In `docs/DEPLOY.md`, inside **Smoke tests post-deploy**, after the existing artisan example block (near `integration:issue-waapi-token`), add:

```markdown
# Green send delay (Yellow Card mitigation) — once after deploy that adds green:apply-send-delay.
# Prefer a low-traffic window: each successful setSettings REBOOTS that Green instance;
# settings can take up to ~5 minutes to apply (Green docs).
# php artisan green:apply-send-delay --dry-run
# php artisan green:apply-send-delay
# Expect brief green_state / send blips while instances reboot; do not re-run in a tight loop.
```

Also add a checklist item:

```markdown
- [ ] (One-shot after delay feature ships) `php artisan green:apply-send-delay --dry-run` then without `--dry-run` (low traffic; Green reboots each instance)
```

- [ ] **Step 2: Align design spec with this plan**

Ensure `docs/superpowers/specs/2026-07-23-green-api-delay-send-messages-design.md` matches:

- Constant: `GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS = 15000`
- Eligible: credentials usable + status **not in** `deleted`, `deleting`
- Ops: document reboot + ~5 min apply window
- Payload-only-delay justified by Green selective `setSettings`
- Header stays: **Estado:** Plan listo — pendiente implementación + plan link

- [ ] **Step 3: Run full related tests once**

Run:

```bash
php artisan test --filter="provision job resumes configuring|ApplyGreenSendDelay"
```

Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add docs/DEPLOY.md docs/superpowers/specs/2026-07-23-green-api-delay-send-messages-design.md docs/superpowers/plans/2026-07-23-green-api-delay-send-messages.md
git commit -m "$(cat <<'EOF'
docs(green): document send-delay apply command and reboot risk

Ops one-shot after deploy, Green reboot/apply-window warning, and design alignment with the implementation plan.
EOF
)"
```

---

## Spec coverage (self-review)

| Spec requirement | Task |
|------------------|------|
| Named constant 15000 (no env) | Task 1 |
| New instances get delay in provisioning `setSettings` | Task 2 |
| Keep webhook fields on provision | Task 2 assert |
| Command `green:apply-send-delay` + `--dry-run` | Task 3 |
| Payload only delay (selective setSettings) | Task 3 assert |
| Eligible = credentials + not deleted/deleting; include failed | Task 3 tests |
| Encrypted token validated in PHP | Task 3 |
| Partial failure continue + exit 1; catch Throwable | Task 3 |
| `docs/DEPLOY.md` one-shot + reboot warning | Task 4 |
| No UI / env / scheduler / campaign pacing | Out of scope — no tasks |

## Placeholder / consistency check

- No TBD/TODO placeholders.
- Command name and delay value match the spec (`green:apply-send-delay`, `15000` via constant).
- `InstanceClient::setSettings` signature unchanged.
- Eligibility and ops reboot semantics match Green API public docs (`setSettings` selective params; instance reboot; apply within ~5 minutes).
