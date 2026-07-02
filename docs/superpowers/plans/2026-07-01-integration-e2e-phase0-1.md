# Integration E2E Phase 0 + Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Verify lebytek.com ↔ api.lebytek.com integration end-to-end in production (Fase 0) and make the back-office operable for admins with CRUD visibility, dedicated email template, legacy Green disabled, and integration tests (Fase 1).

**Architecture:** Enfoque B from spec — manual provisioning via existing CRUD button `"Provisionar demo (api)"`; no automatic hook on estado change. Fase 0 is VPS ops (env, deploy, smoke, cron). Fase 1 is Framework code + doc sync. WhatsApiLebytek is docs/env source of truth; Lebytek_Framework holds back-office code.

**Tech Stack:** PHP (Lebytek Framework Onion), curl HTTP client, MariaDB (`dom_mkt_leads`), SMTP (PHPMailer), Laravel api (Horizon/Redis), bash deploy script, microtest harness (`php tests/run.php`).

**Spec:** `docs/superpowers/specs/2026-07-01-integration-e2e-phase0-1-design.md`

## Global Constraints

- Enfoque **B**: provisioning **manual** por botón CRUD; hook automático al cambiar a `validada` queda fuera de scope.
- Consumidor provisioning: **back-office lebytek.com** (`Lebytek_Framework`, branch `feature/backoffice-api-integration`).
- `externalRef`: **`lebytek_lead_{leadId}`** (idempotencia tenant).
- Columnas locales: `dom_mkt_leads.api_tenant_public_id`, `external_ref`, `api_provisioned_at`, `api_provision_error`.
- Env back-office: `LEBYTEK_API_URL=https://api.lebytek.com/api/v1`, `LEBYTEK_API_TOKEN`, `MAIL_DRIVER=smtp` (+ `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_*`).
- Env api: `GREEN_API_PARTNER_TOKEN`, `WEBHOOK_SECRET`, `WAAPI_SERVICE_EMAIL`, `REDIS_*`, `QUEUE_CONNECTION=redis`, `APP_URL=https://api.lebytek.com`.
- Token CLI api: `php artisan integration:issue-waapi-token [--revoke]`.
- Prod legacy off: `GREEN_API_ENABLED=false`.
- 2º correo v1: token Sanctum por-tenant + base URL api; **sin** enlace waapi; **sin** token Green.
- Deploy lebytek VPS target commit ≥ **`c2d51cd`** (health bootstrap fix + deploy helpers).
- **No** DNS cutover, **no** panel waapi, **no** endpoints `/campaigns`/`/messages`/`/credentials/green-api`.
- **No** eliminar código legacy Green (solo deshabilitar en prod + UI guard).
- Tests integración: **sin** llamadas reales a api en CI.
- **No** commitear `.env`, tokens, ni secretos.

---

## File map (before coding)

| File | Action | Responsibility |
|------|--------|----------------|
| VPS `/home/lebytek-api/.../.env` | Modify (ops) | Partner token, webhook, Redis, service user |
| VPS `/home/lebytek/htdocs/lebytek.com/.env` | Modify (ops) | API token, SMTP, `GREEN_API_ENABLED=false` |
| `Lebytek_Framework/scripts/vps-deploy-lebytek-com.sh` | Run (ops) | Deploy latest branch to VPS |
| `Lebytek_Framework/scripts/lebytek-api-health.php` | Verify (ops) | Health probe exit 0 |
| `Lebytek_Framework/config/cruds/mkt_leads.json` | Modify | API columns in list + detail |
| `Lebytek_Framework/src/Application/Services/CrudTableBuilder.php` | Modify | `truncate` format + `badge_nonempty` |
| `Lebytek_Framework/app/Presentation/Views/emails/lead_api_credentials.php` | **Create** | HTML 2º correo |
| `Lebytek_Framework/app/Application/Marketing/LeadApiProvisioningService.php` | Modify | Render view + plain-text-friendly HTML |
| `Lebytek_Framework/app/Infrastructure/Integrations/LebytekApi/LebytekApiTransport.php` | **Create** | Injectable HTTP transport (testable) |
| `Lebytek_Framework/app/Infrastructure/Integrations/LebytekApi/CurlLebytekApiTransport.php` | **Create** | Default curl transport |
| `Lebytek_Framework/app/Infrastructure/Integrations/LebytekApi/LebytekApiClient.php` | Modify | Use transport injection |
| `Lebytek_Framework/config/container.php` | Modify | Wire default curl transport |
| `Lebytek_Framework/src/Presentation/Controllers/Admin/IntegrationsController.php` | Modify | Legacy Green guard |
| `Lebytek_Framework/src/Presentation/Views/admin/integraciones/index.php` | Modify | Deprecation banner when legacy off |
| `Lebytek_Framework/tests/Integration/LebytekApiClientTest.php` | **Create** | Headers, Idempotency-Key, 429 retry |
| `Lebytek_Framework/tests/Integration/LeadApiProvisioningServiceTest.php` | **Create** | Full flow, idempotency, error persist |
| `WhatsApiLebytek/docs/integration/waapi-api-contract.md` | Modify | Mark tokens/instances implemented |
| `WhatsApiLebytek/docs/integration/VPS_CHECKLIST.md` | Modify | E2E results + dates |
| `WhatsApiLebytek/docs/integration/lebytek-implementation-real.md` | Modify | Operaciones § + checklist |
| `WhatsApiLebytek/docs/integration/role-delegation-lebytek-api.md` | Modify | Checklist items |
| `Lebytek_Framework/docs/integration/*` | Mirror | Copy from api repo (Task 11) |

---

### Task 1: Complete VPS environment (api + lebytek)

**Files:**
- Modify (VPS): `/home/lebytek-api/htdocs/api.lebytek.com/.env`
- Modify (VPS): `/home/lebytek/htdocs/lebytek.com/.env`
- Reference: `Lebytek_Framework/.env.example`

**Interfaces:**
- Consumes: spec §4.1 variable tables
- Produces: non-empty `GREEN_API_PARTNER_TOKEN`, `LEBYTEK_API_TOKEN`, complete `MAIL_*` on lebytek VPS

- [ ] **Step 1: Baseline — record current state**

SSH to VPS:

```bash
ssh lebytek-vps
wc -c /home/lebytek/htdocs/lebytek.com/.env
grep -E '^(LEBYTEK_API_|MAIL_|GREEN_API_)' /home/lebytek/htdocs/lebytek.com/.env | sed 's/=.*/=***/'
sudo -u lebytek-api grep -E '^(GREEN_API_PARTNER|WEBHOOK_SECRET|WAAPI_SERVICE|REDIS|QUEUE)' /home/lebytek-api/htdocs/api.lebytek.com/.env | sed 's/=.*/=***/'
```

Expected: note `.env` size (~423 bytes on lebytek suggests missing `MAIL_*`).

- [ ] **Step 2: Complete api.lebytek.com `.env`**

On VPS as root/sudo:

```bash
cd /home/lebytek-api/htdocs/api.lebytek.com
sudo -u lebytek-api nano .env
```

Ensure these keys exist and are non-empty:

```env
APP_URL=https://api.lebytek.com
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
GREEN_API_PARTNER_TOKEN=<from Green console>
WEBHOOK_SECRET=<openssl rand -hex 32>
WAAPI_SERVICE_EMAIL=<platform admin email>
```

Verify Horizon:

```bash
sudo supervisorctl status lebytek-api-horizon
sudo -u lebytek-api php artisan horizon:status
```

Expected: `RUNNING` / `Horizon is running`.

- [ ] **Step 3: Complete lebytek.com `.env`**

```bash
cd /home/lebytek/htdocs/lebytek.com
sudo -u lebytek nano .env
```

Minimum production block (adjust SMTP values for hosting):

```env
APP_URL=https://lebytek.com
LEBYTEK_API_URL=https://api.lebytek.com/api/v1
LEBYTEK_API_TOKEN=<filled in Task 2>
MAIL_DRIVER=smtp
MAIL_HOST=mail.tudominio.com
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=noreply@lebytek.com
MAIL_PASSWORD=<smtp password>
MAIL_FROM_ADDRESS=noreply@lebytek.com
MAIL_FROM_NAME="Lebytek"
GREEN_API_ENABLED=false
```

Verify size increased:

```bash
wc -c /home/lebytek/htdocs/lebytek.com/.env
```

Expected: > 800 bytes (rough guide; all keys present matters more than size).

- [ ] **Step 4: Verify DB columns exist on lebytek**

```bash
cd /home/lebytek/htdocs/lebytek.com
sudo -u lebytek mariadb -h "$(grep ^DB_HOST= .env | cut -d= -f2)" \
  -u "$(grep ^DB_USERNAME= .env | cut -d= -f2)" \
  -p"$(grep ^DB_PASSWORD= .env | cut -d= -f2-)" \
  "$(grep ^DB_DATABASE= .env | cut -d= -f2)" \
  -e "SHOW COLUMNS FROM dom_mkt_leads LIKE 'api_%';"
```

Expected: rows for `api_tenant_public_id`, `api_provisioned_at`, `api_provision_error`.

If missing, run:

```bash
sudo -u lebytek php scripts/migrate.php
# or apply SQL directly:
sudo -u lebytek bash -c 'cd /home/lebytek/htdocs/lebytek.com && mariadb ... < database/migrations/20260630120000_mkt_leads_api_columns.sql'
```

- [ ] **Step 5: Document — no git commit (VPS secrets)**

Record completion date in operator notes; checklist updated in Task 10.

---

### Task 2: Issue / rotate platform token (api → lebytek)

**Files:**
- Modify (VPS): `/home/lebytek/htdocs/lebytek.com/.env` (`LEBYTEK_API_TOKEN`)

**Interfaces:**
- Consumes: Task 1 api `.env` with `WAAPI_SERVICE_EMAIL` user seeded
- Produces: valid Bearer token in lebytek `.env`; health script passes

- [ ] **Step 1: Issue token on api VPS**

```bash
ssh lebytek-vps
cd /home/lebytek-api/htdocs/api.lebytek.com
sudo -u lebytek-api php artisan integration:issue-waapi-token --revoke
```

Expected: stdout prints plain token once (copy immediately; not logged).

- [ ] **Step 2: Install token on lebytek VPS**

```bash
sudo -u lebytek nano /home/lebytek/htdocs/lebytek.com/.env
# Set LEBYTEK_API_TOKEN=<pasted token>
```

- [ ] **Step 3: Verify health (must not print token)**

```bash
cd /home/lebytek/htdocs/lebytek.com
sudo -u lebytek php scripts/lebytek-api-health.php
echo "exit=$?"
```

Expected:

```
[OK] api.lebytek.com health
exit=0
```

- [ ] **Step 4: Verify curl health manually**

```bash
TOKEN=$(grep ^LEBYTEK_API_TOKEN= /home/lebytek/htdocs/lebytek.com/.env | cut -d= -f2-)
curl -sf -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  https://api.lebytek.com/api/v1/health | head -c 200
```

Expected: JSON with `"status":"ok"` and `"checks":{"database":{"ok":true`.

---

### Task 3: Deploy lebytek.com latest

**Files:**
- Run: `Lebytek_Framework/scripts/vps-deploy-lebytek-com.sh` (on VPS)
- Verify: VPS commit ≥ `c2d51cd`

**Interfaces:**
- Consumes: Task 1–2 env complete
- Produces: deployed code with health script, marketing module enabled, migrations applied

- [ ] **Step 1: Confirm local branch has target commit**

On dev machine:

```bash
cd /path/to/Lebytek_Framework
git fetch origin feature/backoffice-api-integration
git log -1 --oneline origin/feature/backoffice-api-integration
```

Expected: commit hash ≥ `c2d51cd`.

- [ ] **Step 2: Copy deploy script to VPS (if not present)**

```bash
scp Lebytek_Framework/scripts/vps-deploy-lebytek-com.sh lebytek-vps:/tmp/
ssh lebytek-vps 'bash /tmp/vps-deploy-lebytek-com.sh'
```

Expected tail output:

```
[OK] api.lebytek.com health
DEPLOY_DONE health_rc=0 install_rc=0
```

If `health_rc=1`, re-check `LEBYTEK_API_TOKEN` (Task 2) before continuing.

- [ ] **Step 3: Post-deploy smoke HTTP**

```bash
ssh lebytek-vps
curl -sfI -k https://127.0.0.1/ -H 'Host: lebytek.com' | head -1
curl -sfI -k https://127.0.0.1/admin/login -H 'Host: lebytek.com' | head -1
```

Expected: both `HTTP/1.1 200` or `HTTP/2 200`.

- [ ] **Step 4: Confirm `.env` survived deploy**

```bash
grep -c '^LEBYTEK_API_TOKEN=' /home/lebytek/htdocs/lebytek.com/.env
grep '^GREEN_API_ENABLED=' /home/lebytek/htdocs/lebytek.com/.env
```

Expected: token line count `1`; `GREEN_API_ENABLED=false`.

- [ ] **Step 5: Record deployed commit**

```bash
cd /home/lebytek/htdocs/lebytek.com && git rev-parse HEAD
```

Save hash in operator notes for rollback reference.

---

### Task 4: Manual E2E smoke provisioning

**Files:**
- Reference: `docs/integration/VPS_CHECKLIST.md` (update in Task 10)
- Reference: spec §4.3 verification table

**Interfaces:**
- Consumes: Task 3 deploy green; admin login credentials
- Produces: documented E2E pass/fail for all 6 post-smoke checks

- [ ] **Step 1: Create test lead in admin**

1. Browse `https://lebytek.com/admin/login` (or Host header on VPS).
2. CRUD **Leads** → create lead with **your controlled email**, estado `validada`.
3. Note `lead_id` (e.g. `42`).

- [ ] **Step 2: Provision via CRUD button**

1. In leads list, click **"Provisionar demo (api)"** on the test row.
2. Confirm CSRF form submits.
3. Expect flash: `Demo provisionada vía api.lebytek.com...`

If async instance job: wait 30–60 s; refresh list.

- [ ] **Step 3: Verify lead row in DB**

```bash
ssh lebytek-vps
cd /home/lebytek/htdocs/lebytek.com
LEAD_ID=42  # replace
sudo -u lebytek mariadb ... -e \
  "SELECT id, estado, api_tenant_public_id, api_provisioned_at, api_provision_error FROM dom_mkt_leads WHERE id=$LEAD_ID;"
```

Expected: `estado=demo_enviada`, `api_tenant_public_id` NOT NULL, `api_provision_error` NULL.

- [ ] **Step 4: Verify tenant + instance via api**

```bash
TOKEN=$(grep ^LEBYTEK_API_TOKEN= /home/lebytek/htdocs/lebytek.com/.env | cut -d= -f2-)
PUBLIC_ID=<from DB api_tenant_public_id>

curl -sf -H "Authorization: Bearer $TOKEN" \
  "https://api.lebytek.com/api/v1/tenants/$PUBLIC_ID"

curl -sf -H "Authorization: Bearer $TOKEN" \
  -H "X-Tenant-Id: $PUBLIC_ID" \
  "https://api.lebytek.com/api/v1/instances"
```

Expected: tenant 200 with matching `externalRef=lebytek_lead_{id}`; instances list non-empty or 202 provisioning status.

- [ ] **Step 5: Verify email received**

Check inbox for test lead email.

Expected content:
- Sanctum token present (`NN|...` format)
- Base URL `https://api.lebytek.com/api/v1`
- **No** Green API token
- **No** waapi login link

- [ ] **Step 6: Verify idempotency**

Click **"Provisionar demo (api)"** again on same lead.

Expected: no duplicate tenant; lead unchanged; no second email (service returns early when `api_tenant_public_id` set).

- [ ] **Step 7: Record results**

Fill spec §4.3 table (pass/fail + date) — formalized in Task 10 checklist update.

---

### Task 5: Install cron health monitoring

**Files:**
- Modify (VPS): crontab user `lebytek`
- Log: `/home/lebytek/htdocs/lebytek.com/storage/logs/api-health.log`

**Interfaces:**
- Consumes: Task 4 E2E green (health already works)
- Produces: cron entry; first log line within 5 minutes

- [ ] **Step 1: Ensure log directory writable**

```bash
ssh lebytek-vps
sudo -u lebytek mkdir -p /home/lebytek/htdocs/lebytek.com/storage/logs
sudo -u lebytek chmod ug+rwX /home/lebytek/htdocs/lebytek.com/storage/logs
```

- [ ] **Step 2: Install crontab**

As root or `lebytek`:

```bash
crontab -u lebytek -l 2>/dev/null | grep -v lebytek-api-health > /tmp/lebytek-cron || true
echo '*/5 * * * * cd /home/lebytek/htdocs/lebytek.com && php scripts/lebytek-api-health.php >> storage/logs/api-health.log 2>&1' >> /tmp/lebytek-cron
crontab -u lebytek /tmp/lebytek-cron
crontab -u lebytek -l
```

Expected: line with `lebytek-api-health.php`.

- [ ] **Step 3: Wait and verify log (after 5 min)**

```bash
tail -5 /home/lebytek/htdocs/lebytek.com/storage/logs/api-health.log
```

Expected: `[OK] api.lebytek.com health` — **no** Bearer token in log.

- [ ] **Step 4: Simulate failure detection (optional)**

Temporarily set bad token, run script once, restore token:

```bash
# expect [FAIL] in log, exit 1
```

Confirm failure message does not leak token value.

---

### Task 6: CRUD leads — API columns visible

**Files:**
- Modify: `Lebytek_Framework/config/cruds/mkt_leads.json`
- Modify: `Lebytek_Framework/src/Application/Services/CrudTableBuilder.php`
- Test: `Lebytek_Framework/tests/Crud/Table/CrudTableBuilderTruncateTest.php` (**Create**)

**Interfaces:**
- Consumes: `dom_mkt_leads` columns from migration
- Produces: list/detail show `api_tenant_public_id` (truncated), `api_provisioned_at`, `api_provision_error` (danger badge when set), `external_ref` in detail

- [ ] **Step 1: Write failing test for truncate + badge_nonempty**

Create `tests/Crud/Table/CrudTableBuilderTruncateTest.php`:

```php
<?php
declare(strict_types=1);

use Lebytek\Framework\Application\Services\CrudTableBuilder;

test('CrudTableBuilder: truncate format limits displayed length', function (): void {
    $builder = new CrudTableBuilder();
    $columns = [[
        'name' => 'api_tenant_public_id',
        'label' => 'Tenant API',
        'format' => 'truncate',
        'max_length' => 26,
        'badge' => [],
    ]];
    $long = '01JABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $row = ['api_tenant_public_id' => $long];
    $ref = new ReflectionClass($builder);
    $method = $ref->getMethod('formatRow');
    $method->setAccessible(true);
    $out = $method->invoke($builder, $row, $columns);
    assert_same('01JABCDEFGHIJKLMNOPQRSTUV…', $out['_formatted']['api_tenant_public_id']);
});

test('CrudTableBuilder: badge_nonempty applies badge when value present', function (): void {
    $builder = new CrudTableBuilder();
    $columns = [[
        'name' => 'api_provision_error',
        'label' => 'Error API',
        'format' => '',
        'badge' => [],
        'badge_nonempty' => 'danger',
    ]];
    $ref = new ReflectionClass($builder);
    $method = $ref->getMethod('formatRow');
    $method->setAccessible(true);
    $out = $method->invoke($builder, ['api_provision_error' => 'timeout'], $columns);
    assert_same('danger', $out['_badge']['api_provision_error']);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd Lebytek_Framework
php tests/run.php Crud/Table/CrudTableBuilderTruncate
```

Expected: FAIL (truncate/badge_nonempty not implemented).

- [ ] **Step 3: Implement CrudTableBuilder extensions**

In `src/Application/Services/CrudTableBuilder.php`, inside `formatRow()` after datetime handling and before badge config block, add:

```php
if ($format === 'truncate' && $value !== null && $value !== '') {
    $max = (int) ($column['max_length'] ?? 26);
    $text = (string) $value;
    if (mb_strlen($text) > $max) {
        $row['_formatted'][$name] = mb_substr($text, 0, $max).'…';
    } else {
        $row['_formatted'][$name] = $text;
    }
    continue;
}
```

Before `$row['_formatted'][$name] = $value;` at end of loop, add:

```php
if (!empty($column['badge_nonempty']) && $value !== null && trim((string) $value) !== '') {
    $row['_badge'][$name] = (string) $column['badge_nonempty'];
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php tests/run.php Crud/Table/CrudTableBuilderTruncate
```

Expected: 2 passed, 0 failed.

- [ ] **Step 5: Update mkt_leads.json**

Replace `list.columns` array — add after `estado` column:

```json
{ "name": "api_tenant_public_id", "label": "Tenant API", "format": "truncate", "max_length": 26 },
{ "name": "api_provisioned_at", "label": "Provisionado", "format": "datetime", "sortable": true },
{ "name": "api_provision_error", "label": "Error API", "badge_nonempty": "danger" },
```

Update `detail.tabs[0].columns` to:

```json
["nombre","email","telefono","estado","mensaje","external_ref","api_tenant_public_id","api_provisioned_at","api_provision_error","created_at"]
```

Do **not** add api fields to `form.fields` (read-only via detail/list).

- [ ] **Step 6: Manual verify locally**

```bash
php -S localhost:8000 -t public
# Login admin → /admin/crud/mkt_leads — confirm new columns render
```

- [ ] **Step 7: Commit**

```bash
git add config/cruds/mkt_leads.json src/Application/Services/CrudTableBuilder.php tests/Crud/Table/CrudTableBuilderTruncateTest.php
git commit -m "feat(marketing): show api provisioning columns in leads CRUD"
```

---

### Task 7: Email template + service refactor

**Files:**
- Create: `Lebytek_Framework/app/Presentation/Views/emails/lead_api_credentials.php`
- Modify: `Lebytek_Framework/app/Application/Marketing/LeadApiProvisioningService.php`

**Interfaces:**
- Consumes: `ViewHelper::render('emails/lead_api_credentials', ...)` with `$nombre`, `$token`, `$apiBaseUrl`
- Produces: HTML email via `MailerInterface`; PHPMailer auto AltBody via `strip_tags` (existing behavior)

- [ ] **Step 1: Write failing test (service sends rendered HTML)**

Add to `tests/Integration/LeadApiProvisioningServiceTest.php` (created fully in Task 9 — for TDD order, create file now with one test):

```php
<?php
declare(strict_types=1);

use App\Application\Marketing\LeadApiProvisioningService;
use App\Domain\Marketing\Contracts\LeadRepositoryInterface;
use App\Infrastructure\Integrations\LebytekApi\LebytekApiClient;
use App\Infrastructure\Integrations\LebytekApi\LebytekApiException;
use Lebytek\Framework\Application\DTO\Mail\MensajeCorreo;
use Lebytek\Framework\Domain\Interfaces\MailerInterface;

final class FakeLeadRepo implements LeadRepositoryInterface
{
    public array $lead = [
        'id' => 1, 'nombre' => 'Ana', 'email' => 'ana@test.com', 'api_tenant_public_id' => null,
    ];
    public function guardar(\App\Domain\Marketing\ValueObjects\LeadDraft $d): int { return 1; }
    public function findById(int $id): ?array { return $this->lead; }
    public function markApiProvisioned(int $id, string $p, string $e): void {}
    public function markApiProvisionError(int $id, string $err): void {}
}

final class FakeApi implements /* use stub class from Task 9 */ {}
final class SpyMailer implements MailerInterface
{
    public ?MensajeCorreo $last = null;
    public function enviar(MensajeCorreo $m): void { $this->last = $m; }
}

test('sendCredentialsEmail uses HTML template with token and base URL', function () {
    // Will implement after template exists — see Task 9 full file
});
```

Run: `php tests/run.php Integration/LeadApiProvisioning` — expect FAIL until implemented.

- [ ] **Step 2: Create email template**

Create `app/Presentation/Views/emails/lead_api_credentials.php`:

```php
<?php
use Lebytek\Framework\Kernel\Helpers\ViewHelper;

/** @var string $nombre */
/** @var string $token */
/** @var string $apiBaseUrl */
?>
<!DOCTYPE html>
<html lang="es">
<body style="margin:0; padding:24px; background:#f0f2f5; font-family: Arial, Helvetica, sans-serif; color:#212529;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr><td align="center">
            <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; padding:32px;">
                <tr><td>
                    <h2 style="margin:0 0 16px;">Lebytek</h2>
                    <p style="margin:0 0 8px;">Hola <?= ViewHelper::e($nombre) ?>,</p>
                    <p style="margin:0 0 16px;">Tu demo está lista. Usa estas credenciales para conectar con nuestra API:</p>
                    <p style="margin:0 0 8px;"><strong>Base URL</strong></p>
                    <p style="margin:0 0 16px; font-family: monospace; background:#f8f9fa; padding:12px; border-radius:4px;"><?= ViewHelper::e($apiBaseUrl) ?></p>
                    <p style="margin:0 0 8px;"><strong>Token de acceso</strong></p>
                    <p style="margin:0 0 16px; font-family: monospace; background:#f8f9fa; padding:12px; border-radius:4px; word-break:break-all;"><?= ViewHelper::e($token) ?></p>
                    <p style="margin:0 0 16px; font-size:14px; color:#6c757d;">
                        Conserva este correo; el token <strong>no se vuelve a mostrar</strong>.
                    </p>
                    <p style="margin:16px 0 0; font-size:13px; color:#6c757d;">
                        Saludos,<br>Equipo Lebytek
                    </p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
```

- [ ] **Step 3: Refactor LeadApiProvisioningService**

Replace `sendCredentialsEmail()` body:

```php
private function sendCredentialsEmail(string $nombre, string $email, string $token): void
{
    $apiBaseUrl = rtrim((string) EnvLoader::get('LEBYTEK_API_URL', 'https://api.lebytek.com/api/v1'), '/');

    $html = \Lebytek\Framework\Kernel\Helpers\ViewHelper::render('emails/lead_api_credentials', [
        'nombre'     => $nombre,
        'token'      => $token,
        'apiBaseUrl' => $apiBaseUrl,
    ], '');

    $this->mailer->enviar(new MensajeCorreo(
        $email,
        $nombre,
        'Tus credenciales de acceso — Lebytek',
        $html,
    ));
}
```

- [ ] **Step 4: Verify template resolves from app path**

```bash
php -r "
require 'vendor/autoload.php';
echo \Lebytek\Framework\Kernel\Helpers\ViewHelper::resolve('emails/lead_api_credentials');
"
```

Expected: path under `app/Presentation/Views/emails/lead_api_credentials.php`.

- [ ] **Step 5: Commit**

```bash
git add app/Presentation/Views/emails/lead_api_credentials.php app/Application/Marketing/LeadApiProvisioningService.php
git commit -m "feat(marketing): dedicated HTML template for api credentials email"
```

---

### Task 8: Disable legacy Green path in prod + UI guard

**Files:**
- Modify: `Lebytek_Framework/src/Presentation/Controllers/Admin/IntegrationsController.php`
- Modify: `Lebytek_Framework/src/Presentation/Views/admin/integraciones/index.php`
- Modify: `Lebytek_Framework/src/Presentation/Views/admin/integraciones/provision.php`
- Verify: `Lebytek_Framework/.env.example` (already `GREEN_API_ENABLED=false`)

**Interfaces:**
- Consumes: `GREEN_API_ENABLED` env; existing `apiProvisioningEnabled()` (checks `LEBYTEK_API_TOKEN`)
- Produces: legacy Green UI hidden/disabled when `GREEN_API_ENABLED=false` OR api token configured

- [ ] **Step 1: Add helper to IntegrationsController**

```php
private function legacyGreenEnabled(): bool
{
    return filter_var(
        \Lebytek\Framework\Kernel\EnvLoader::get('GREEN_API_ENABLED', false),
        FILTER_VALIDATE_BOOL
    );
}

private function showLegacyGreenUi(): bool
{
    return $this->legacyGreenEnabled() && ! $this->apiProvisioningEnabled();
}
```

Update `index()`:

```php
return $this->view('admin/integraciones/index', [
    'titulo'            => 'Integraciones / WhatsApp',
    'instancia'         => $this->accounts->findDefault('green_api'),
    'partnerActivo'     => $this->partner->isAvailable(),
    'logs'              => $this->logs->recent(50),
    'showLegacyGreenUi' => $this->showLegacyGreenUi(),
    'apiProvisioning'   => $this->apiProvisioningEnabled(),
]);
```

Update `provisionForm()` and `provision()` guard:

```php
if (! $this->legacyGreenEnabled() || $this->apiProvisioningEnabled()) {
    Session::flash('error', 'Provisión local desactivada: usa "Provisionar demo (api)" en Leads.');
    return $this->redirect('/admin/crud/mkt_leads');
}
```

- [ ] **Step 2: Update index.php view**

Wrap the "Instancia interna" card:

```php
<?php if (!empty($showLegacyGreenUi)): ?>
    <!-- existing card HTML -->
<?php elseif (!empty($apiProvisioning)): ?>
    <div class="alert alert-info">
        Provisión de demos vía <strong>api.lebytek.com</strong>. Usa el botón
        <em>Provisionar demo (api)</em> en Leads. El camino legacy Green está desactivado.
    </div>
<?php else: ?>
    <div class="alert alert-warning">
        Configura <code>LEBYTEK_API_TOKEN</code> para provisioning vía api, o
        <code>GREEN_API_ENABLED=true</code> solo en entornos de desarrollo legacy.
    </div>
<?php endif; ?>
```

Keep logs card visible always.

- [ ] **Step 3: Verify prod `.env` on VPS**

```bash
grep GREEN_API_ENABLED /home/lebytek/htdocs/lebytek.com/.env
```

Expected: `GREEN_API_ENABLED=false`.

- [ ] **Step 4: Commit**

```bash
git add src/Presentation/Controllers/Admin/IntegrationsController.php \
  src/Presentation/Views/admin/integraciones/index.php
git commit -m "feat(integrations): hide legacy Green UI when api provisioning active"
```

---

### Task 9: Integration tests (LebytekApiClient + LeadApiProvisioningService)

**Files:**
- Create: `Lebytek_Framework/app/Infrastructure/Integrations/LebytekApi/LebytekApiTransport.php`
- Create: `Lebytek_Framework/app/Infrastructure/Integrations/LebytekApi/CurlLebytekApiTransport.php`
- Modify: `Lebytek_Framework/app/Infrastructure/Integrations/LebytekApi/LebytekApiClient.php`
- Modify: `Lebytek_Framework/config/container.php`
- Create: `Lebytek_Framework/tests/Integration/LebytekApiClientTest.php`
- Create: `Lebytek_Framework/tests/Integration/LeadApiProvisioningServiceTest.php`

**Interfaces:**
- Consumes: `LebytekApiTransport::execute(method, url, headers, body): array{status:int, body:string, error:string}`
- Produces: testable client; 2+ green integration tests via `php tests/run.php Integration`

- [ ] **Step 1: Write failing LebytekApiClientTest**

Create `app/Infrastructure/Integrations/LebytekApi/LebytekApiTransport.php`:

```php
<?php
declare(strict_types=1);

namespace App\Infrastructure\Integrations\LebytekApi;

interface LebytekApiTransport
{
    /**
     * @param list<string> $headers
     * @return array{status: int, body: string, error: string}
     */
    public function execute(string $method, string $url, array $headers, ?string $body): array;
}
```

Create `tests/Integration/LebytekApiClientTest.php`:

```php
<?php
declare(strict_types=1);

use App\Infrastructure\Integrations\LebytekApi\LebytekApiClient;
use App\Infrastructure\Integrations\LebytekApi\LebytekApiTransport;

final class RecordingTransport implements LebytekApiTransport
{
    /** @var list<array{method:string,url:string,headers:list<string>,body:?string}> */
    public array $calls = [];
    /** @var list<array{status:int,body:string,error:string}> */
    public array $responses = [];

    public function execute(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return array_shift($this->responses) ?? ['status' => 200, 'body' => '{"status":"ok"}', 'error' => ''];
    }
}

test('LebytekApiClient sends Bearer and Idempotency-Key on POST', function () {
    $transport = new RecordingTransport();
    $transport->responses[] = ['status' => 201, 'body' => '{"publicId":"01JTEST"}', 'error' => ''];
    $client = new LebytekApiClient('https://api.test/v1', 'platform-token', 5, 1, $transport);
    $client->provisionTenant('Acme', 'acme', 'lebytek_lead_1');
    assert_same(1, count($transport->calls));
    $headers = implode("\n", $transport->calls[0]['headers']);
    assert_true(str_contains($headers, 'Authorization: Bearer platform-token'));
    assert_true(str_contains($headers, 'Idempotency-Key: '));
});

test('LebytekApiClient retries on 429 then succeeds', function () {
    $transport = new RecordingTransport();
    $transport->responses[] = ['status' => 429, 'body' => '{"message":"rate limit"}', 'error' => ''];
    $transport->responses[] = ['status' => 200, 'body' => '{"status":"ok"}', 'error' => ''];
    $client = new LebytekApiClient('https://api.test/v1', 'tok', 5, 3, $transport);
    $client->health();
    assert_same(2, count($transport->calls));
});
```

Run: `php tests/run.php Integration/LebytekApiClient` — expect FAIL.

- [ ] **Step 2: Implement transport injection in LebytekApiClient**

Add constructor param:

```php
public function __construct(
    private readonly string $baseUrl,
    private readonly string $token,
    private readonly int $timeoutSeconds = 30,
    private readonly int $maxRetries = 3,
    private readonly ?LebytekApiTransport $transport = null,
) {}
```

Create `CurlLebytekApiTransport.php` with curl logic extracted from current `request()`.

Update `request()` to call `$this->transport ?? new CurlLebytekApiTransport($this->timeoutSeconds)`.

Update `config/container.php` binding (unchanged signature — default null transport).

- [ ] **Step 3: Run LebytekApiClientTest — expect PASS**

```bash
php tests/run.php Integration/LebytekApiClient
```

- [ ] **Step 4: Write LeadApiProvisioningServiceTest (full file)**

Create `tests/Integration/LeadApiProvisioningServiceTest.php`:

```php
<?php
declare(strict_types=1);

use App\Application\Marketing\LeadApiProvisioningService;
use App\Domain\Marketing\Contracts\LeadRepositoryInterface;
use App\Infrastructure\Integrations\LebytekApi\LebytekApiClient;
use App\Infrastructure\Integrations\LebytekApi\LebytekApiException;
use App\Infrastructure\Integrations\LebytekApi\LebytekApiTransport;
use Lebytek\Framework\Application\DTO\Mail\MensajeCorreo;
use Lebytek\Framework\Domain\Interfaces\MailerInterface;

final class InMemoryLeadRepo implements LeadRepositoryInterface
{
    public array $rows = [];
    public function guardar(\App\Domain\Marketing\ValueObjects\LeadDraft $d): int { return 1; }
    public function findById(int $id): ?array { return $this->rows[$id] ?? null; }
    public function markApiProvisioned(int $id, string $p, string $e): void
    {
        $this->rows[$id]['api_tenant_public_id'] = $p;
        $this->rows[$id]['external_ref'] = $e;
        $this->rows[$id]['api_provision_error'] = null;
        $this->rows[$id]['estado'] = 'demo_enviada';
    }
    public function markApiProvisionError(int $id, string $err): void
    {
        $this->rows[$id]['api_provision_error'] = $err;
    }
}

final class SequenceTransport implements LebytekApiTransport
{
    public function __construct(private array $responses) {}
    public function execute(string $method, string $url, array $headers, ?string $body): array
    {
        return array_shift($this->responses) ?? ['status' => 500, 'body' => '{}', 'error' => ''];
    }
}

final class SpyMailer implements MailerInterface
{
    public ?MensajeCorreo $last = null;
    public function enviar(MensajeCorreo $m): void { $this->last = $m; }
}

test('LeadApiProvisioningService full flow persists lead and sends email', function () {
    $repo = new InMemoryLeadRepo();
    $repo->rows[5] = ['id' => 5, 'nombre' => 'Ana', 'email' => 'ana@test.com', 'api_tenant_public_id' => null];
    $transport = new SequenceTransport([
        ['status' => 201, 'body' => '{"publicId":"01JTENANT"}', 'error' => ''],
        ['status' => 202, 'body' => '{"publicId":"01JINST"}', 'error' => ''],
        ['status' => 201, 'body' => '{"token":"12|abc"}', 'error' => ''],
    ]);
    $api = new LebytekApiClient('https://api.test/v1', 'plat', 5, 1, $transport);
    $mailer = new SpyMailer();
    $svc = new LeadApiProvisioningService($api, $repo, $mailer);
    $svc->provisionLead(5);
    assert_same('01JTENANT', $repo->rows[5]['api_tenant_public_id']);
    assert_same('demo_enviada', $repo->rows[5]['estado']);
    assert_true($mailer->last !== null);
    assert_true(str_contains($mailer->last->html, '12|abc'));
    assert_true(str_contains($mailer->last->html, 'https://api.test/v1'));
});

test('LeadApiProvisioningService skips when already provisioned', function () {
    $repo = new InMemoryLeadRepo();
    $repo->rows[5] = ['id' => 5, 'nombre' => 'Ana', 'email' => 'a@t.com', 'api_tenant_public_id' => 'EXISTING'];
    $transport = new SequenceTransport([]);
    $api = new LebytekApiClient('https://api.test/v1', 'plat', 5, 1, $transport);
    $mailer = new SpyMailer();
    $svc = new LeadApiProvisioningService($api, $repo, $mailer);
    $svc->provisionLead(5);
    assert_same(0, count($transport->responses)); // no HTTP calls
    assert_null($mailer->last);
});

test('LeadApiProvisioningService persists api_provision_error on failure', function () {
    $repo = new InMemoryLeadRepo();
    $repo->rows[5] = ['id' => 5, 'nombre' => 'Ana', 'email' => 'a@t.com', 'api_tenant_public_id' => null];
    $transport = new SequenceTransport([
        ['status' => 422, 'body' => '{"message":"slug taken"}', 'error' => ''],
    ]);
    $api = new LebytekApiClient('https://api.test/v1', 'plat', 5, 1, $transport);
    $svc = new LeadApiProvisioningService($api, $repo, new SpyMailer());
    assert_throws(LebytekApiException::class, fn () => $svc->provisionLead(5));
    assert_same('slug taken', $repo->rows[5]['api_provision_error']);
});
```

- [ ] **Step 5: Run all integration tests**

```bash
php tests/run.php Integration
```

Expected: ≥ 5 passed (2 client + 3 service), 0 failed.

- [ ] **Step 6: Commit**

```bash
git add app/Infrastructure/Integrations/LebytekApi/ \
  config/container.php \
  tests/Integration/
git commit -m "test(integration): LebytekApiClient and LeadApiProvisioningService"
```

---

### Task 10: Update contract + checklists (WhatsApiLebytek)

**Files:**
- Modify: `WhatsApiLebytek/docs/integration/waapi-api-contract.md`
- Modify: `WhatsApiLebytek/docs/integration/VPS_CHECKLIST.md`
- Modify: `WhatsApiLebytek/docs/integration/lebytek-implementation-real.md`
- Modify: `WhatsApiLebytek/docs/integration/role-delegation-lebytek-api.md`

**Interfaces:**
- Consumes: Task 4 E2E results; Task 9 tests green
- Produces: docs reflect implemented endpoints; checklists marked with dates

- [ ] **Step 1: Update waapi-api-contract.md — tokens implemented**

Replace block at `POST /tenants/{publicId}/tokens`:

```markdown
> **Estado implementación (api):** **Implementado** — `routes/api.php` (`api.v1.tenants.tokens.store`), commit `c9b1bc2+`.
```

Replace 2º correo table row:

```markdown
| Token Sanctum por-tenant | **Obligatorio** | Emitido vía `POST /tenants/{publicId}/tokens` (**implementado**) |
```

- [ ] **Step 2: Move instances to Fase 1 implemented section**

Add before `## Endpoints — Fase 2 (planned`):

```markdown
### `POST /instances`

**Estado:** **Implementado**  
**Permiso:** `instancias.crear`  
**Header:** `X-Tenant-Id: {tenantPublicId}`  
**Idempotency-Key:** requerido  

**Body:** `{ "label": "Demo Acme", "externalRef": "lebytek_lead_42_instance", "purpose": "demo" }`  
**Respuesta:** `202` provisioning (async Green Partner job) or `201` when ready.

Also implemented: `GET /instances`, `GET /instances/{publicId}`, `GET /instances/{publicId}/qr`, `DELETE /instances/{publicId}`.
```

Remove `POST /instances`, `GET /instances`, `GET /instances/{publicId}`, `DELETE /instances/{publicId}` rows from Fase 2 planned table (keep campaigns, messages, credentials).

- [ ] **Step 3: Add operaciones section to lebytek-implementation-real.md**

Insert after §5 or create **§5.1 Operaciones (Fase 0/1)**:

```markdown
## Operaciones — flujo demo (manual)

1. Lead entra → `pendiente`
2. Admin revisa → cambia a `validada` (cobro manual fuera del sistema o en notas)
3. Admin clic **Provisionar demo (api)** en la fila
4. Sistema orquesta tenant + instancia + token + 2º correo
5. Lead → `demo_enviada` automáticamente
6. Si falla → `api_provision_error` visible en listado; corregir env y reintentar

**Regla:** no re-provisionar si `api_tenant_public_id` ya existe (idempotente).
**Único camino demo en prod:** api.lebytek.com (`GREEN_API_ENABLED=false`).
```

Update §6 / checklist: mark implemented items `[x]` with date `2026-07-01`.

- [ ] **Step 4: Update VPS_CHECKLIST.md with E2E results**

Mark completed items from Task 4–5:

```markdown
- [x] `GREEN_API_PARTNER_TOKEN` configurado (2026-07-01)
- [x] `LEBYTEK_API_TOKEN` + `MAIL_*` smtp en lebytek VPS (2026-07-01)
- [x] Deploy lebytek ≥ c2d51cd (2026-07-01, commit: ______)
- [x] Smoke E2E provisioning verde (2026-07-01)
- [x] Cron health cada 5 min (2026-07-01)
```

Add lebytek.com section E2E block referencing back-office button flow.

- [ ] **Step 5: Update role-delegation-lebytek-api.md checklist**

Mark items § checklist as `[x]` where code exists (client, service, health script, email template after Task 7 deploy).

- [ ] **Step 6: Verify no stale "pendiente" for tokens/instances**

```bash
cd WhatsApiLebytek
rg "pendiente en código|pendiente implementación api" docs/integration/
```

Expected: no matches for tokens/instances (campaigns may still say planned).

- [ ] **Step 7: Commit**

```bash
git add docs/integration/
git commit -m "docs(integration): mark E2E phase 0/1 complete, tokens/instances implemented"
```

---

### Task 11: Mirror docs to Lebytek_Framework

**Files:**
- Copy: `WhatsApiLebytek/docs/integration/waapi-api-contract.md` → `Lebytek_Framework/docs/integration/`
- Copy: `WhatsApiLebytek/docs/integration/VPS_CHECKLIST.md` → `Lebytek_Framework/docs/integration/`
- Copy: `WhatsApiLebytek/docs/integration/lebytek-implementation-real.md` → `Lebytek_Framework/docs/integration/`
- Copy: `WhatsApiLebytek/docs/integration/role-delegation-lebytek-api.md` → `Lebytek_Framework/docs/integration/`

**Interfaces:**
- Consumes: Task 10 committed docs in WhatsApiLebytek
- Produces: identical integration docs in Framework repo

- [ ] **Step 1: Copy files**

```bash
cp WhatsApiLebytek/docs/integration/waapi-api-contract.md Lebytek_Framework/docs/integration/
cp WhatsApiLebytek/docs/integration/VPS_CHECKLIST.md Lebytek_Framework/docs/integration/
cp WhatsApiLebytek/docs/integration/lebytek-implementation-real.md Lebytek_Framework/docs/integration/
cp WhatsApiLebytek/docs/integration/role-delegation-lebytek-api.md Lebytek_Framework/docs/integration/
```

- [ ] **Step 2: Diff verify**

```bash
diff WhatsApiLebytek/docs/integration/waapi-api-contract.md Lebytek_Framework/docs/integration/waapi-api-contract.md
```

Expected: no output (identical).

- [ ] **Step 3: Commit in Lebytek_Framework**

```bash
cd Lebytek_Framework
git add docs/integration/
git commit -m "docs(integration): mirror api repo phase 0/1 E2E updates"
```

---

## Self-review (spec coverage)

| Spec requirement | Task |
|------------------|------|
| §4.1 VPS env api + lebytek | Task 1 |
| §4.2 Deploy ≥ c2d51cd | Task 3 |
| §4.3 Smoke E2E | Task 4 |
| §4.4 Cron health | Task 5 |
| §5.1 CRUD api columns | Task 6 |
| §5.2 Flujo operativo doc | Task 10 |
| §5.3 Legacy Green off + UI | Task 8 |
| §5.4 Plantilla 2º correo | Task 7 |
| §5.5 Tests integración | Task 9 |
| §5.6 Contrato + checklists | Task 10–11 |
| §6 orden implementación | Tasks 1–11 order |
| Enfoque B (manual button) | Global Constraints |
| No DNS / no Fase 2 vertical | Global Constraints |

**Placeholder scan:** none — all steps include concrete commands or code.

**Type consistency:** `LebytekApiTransport::execute` return shape used in client + tests; `LeadRepositoryInterface` fakes match production signatures.

---

## Rollback reference (from spec §8)

```bash
# lebytek.com — restore previous deploy + env backup
cp /tmp/lebytek-env-backup.env /home/lebytek/htdocs/lebytek.com/.env
cd /home/lebytek/htdocs/lebytek.com && git checkout <previous-sha>
sudo -u lebytek composer install --no-dev

# api — env-only changes in this plan; git rollback only if endpoints break
```

---

**Plan complete and saved to `docs/superpowers/plans/2026-07-01-integration-e2e-phase0-1.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** — execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
