# lebytek.com — implementación real (espejo del contrato api)

Guía operativa para **Lebytek_Framework** (`lebytek.com` VPS). Repo: branch `feature/backoffice-api-integration`.

Complementa el contrato abstracto en [waapi-api-contract.md](waapi-api-contract.md) y la delegación de roles en [role-delegation-lebytek-api.md](role-delegation-lebytek-api.md).

---

## 1. Qué ya existe en api (no reimplementar)

| Capacidad | Dónde vive en api | Back-office hace |
|-----------|-------------------|------------------|
| Crear tenant técnico | `POST /api/v1/tenants` | Orquestar + guardar `publicId` en `dom_mkt_leads` |
| Idempotencia tenant | `externalRef` + `core_tenants.external_ref` | Enviar `lebytek_lead_{id}` |
| Idempotencia HTTP | Middleware `ApiIdempotencyKey` | Header `Idempotency-Key` en POST/PATCH |
| RBAC / permisos | Spatie en api | Solo Bearer token plataforma |
| Multi-tenant datos WhatsApp | `BelongsToTenant` + Redis colas | Enviar `X-Tenant-Id` en Fase 2 |
| Green API | `int_credenciales`, jobs, webhooks | **Nada** |
| Health motor | `GET /api/v1/health` | Monitoreo periódico (cron PHP) |

---

## 2. Variables `.env`

```env
# Obligatorias fase 1
LEBYTEK_API_URL=https://api.lebytek.com/api/v1
LEBYTEK_API_TOKEN=           # Sanctum plain token — NUNCA en git

# Recomendadas
LEBYTEK_API_TIMEOUT=30
LEBYTEK_API_RETRY_MAX=3
LEBYTEK_API_RETRY_DELAY_MS=500

# 2º correo
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@lebytek.com
MAIL_FROM_NAME="Lebytek"
```

**Obtener token (una vez en VPS api):**

```bash
cd /home/lebytek-api/htdocs/api.lebytek.com
sudo -u lebytek-api php artisan integration:issue-waapi-token --revoke
```

Copiar salida → `LEBYTEK_API_TOKEN` en lebytek.com `.env`. Rotar periódicamente con `--revoke`.

---

## 3. Migración `dom_mkt_leads`

```sql
ALTER TABLE dom_mkt_leads
  ADD COLUMN api_tenant_public_id CHAR(26) NULL,
  ADD COLUMN external_ref VARCHAR(255) NULL,
  ADD COLUMN api_provisioned_at TIMESTAMP NULL,
  ADD COLUMN api_provision_error TEXT NULL,
  ADD UNIQUE KEY dom_mkt_leads_api_tenant_public_id_unique (api_tenant_public_id),
  ADD UNIQUE KEY dom_mkt_leads_external_ref_unique (external_ref);
```

| Columna | Valor |
|---------|-------|
| `external_ref` | `lebytek_lead_{id}` — **mismo** string en cada llamada |
| `api_tenant_public_id` | `publicId` ULID de la respuesta api |
| `api_provisioned_at` | timestamp al recibir 200/201 exitoso |
| `api_provision_error` | último error JSON/texto si falló |

---

## 4. LebytekApiClient (curl)

**Namespace:** `App\Infrastructure\Integrations\LebytekApi\LebytekApiClient`

Sin `Illuminate\`, sin Eloquent. Binding en `config/container.php`.

### 4.1 Headers que api exige

| Operación | Headers |
|-----------|---------|
| Todas | `Authorization: Bearer {token}`, `Accept: application/json` |
| POST/PATCH | `Content-Type: application/json`, `Idempotency-Key: {uuid}` |
| Fase 2 tenant-scoped | `X-Tenant-Id: {api_tenant_public_id}` |

### 4.2 Código base (curl puro)

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations\LebytekApi;

final class LebytekApiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeoutSeconds = 30,
        private readonly int $maxRetries = 3,
    ) {}

    public function health(?string $actingTenantPublicId = null): array
    {
        return $this->request('GET', '/health', headers: $this->tenantHeaders($actingTenantPublicId));
    }

    public function provisionTenant(string $name, string $slug, string $externalRef): array
    {
        return $this->request('POST', '/tenants', [
            'name' => $name,
            'slug' => $slug,
            'externalRef' => $externalRef,
        ]);
    }

    public function getTenant(string $publicId): array
    {
        return $this->request('GET', '/tenants/'.$publicId);
    }

    private function request(
        string $method,
        string $path,
        ?array $body = null,
        array $headers = [],
    ): array {
        $url = rtrim($this->baseUrl, '/').$path;
        $write = in_array($method, ['POST', 'PUT', 'PATCH'], true);

        $baseHeaders = [
            'Authorization: Bearer '.$this->token,
            'Accept: application/json',
        ];

        if ($write) {
            $baseHeaders[] = 'Content-Type: application/json';
            $baseHeaders[] = 'Idempotency-Key: '.$this->newUuid();
        }

        $allHeaders = array_merge($baseHeaders, $headers);
        $attempt = 0;
        $delayMs = 500;

        while (true) {
            $attempt++;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_HTTPHEADER => $allHeaders,
            ]);

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
            }

            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                if ($attempt < $this->maxRetries) {
                    usleep($delayMs * 1000);
                    $delayMs *= 2;
                    continue;
                }
                throw new LebytekApiException('Connection failed: '.$curlError, 0);
            }

            $decoded = json_decode($raw, true) ?? ['message' => $raw];

            if ($status === 429 || $status >= 500) {
                if ($attempt < $this->maxRetries) {
                    usleep($delayMs * 1000);
                    $delayMs *= 2;
                    continue;
                }
            }

            if ($status >= 400) {
                throw new LebytekApiException(
                    $decoded['message'] ?? 'API error',
                    $status,
                    $decoded['errors'] ?? null,
                );
            }

            return $decoded;
        }
    }

    /** @return list<string> */
    private function tenantHeaders(?string $actingTenantPublicId): array
    {
        return $actingTenantPublicId !== null
            ? ['X-Tenant-Id: '.$actingTenantPublicId]
            : [];
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
```

**Binding en `config/container.php`:**

```php
$container->singleton(LebytekApiClient::class, fn () => new LebytekApiClient(
    baseUrl: getenv('LEBYTEK_API_URL') ?: '',
    token: getenv('LEBYTEK_API_TOKEN') ?: '',
    timeoutSeconds: (int) (getenv('LEBYTEK_API_TIMEOUT') ?: 30),
    maxRetries: (int) (getenv('LEBYTEK_API_RETRY_MAX') ?: 3),
));
```

---

## 5. LeadApiProvisioningService

**Namespace sugerido:** `App\Application\Marketing\LeadApiProvisioningService`

**Disparador:** admin aprueba lead (acción CRUD / handler) — **no** post-registro de organization.

```php
<?php

declare(strict_types=1);

namespace App\Application\Marketing;

use App\Infrastructure\Integrations\LebytekApi\LebytekApiClient;
use App\Infrastructure\Integrations\LebytekApi\LebytekApiException;

final class LeadApiProvisioningService
{
    public function __construct(private readonly LebytekApiClient $api) {}

    public function provision(int $leadId, string $name, string $slug): void
    {
        $externalRef = 'lebytek_lead_'.$leadId;

        try {
            $response = $this->api->provisionTenant($name, $slug, $externalRef);
            // Persistir en dom_mkt_leads:
            // api_tenant_public_id = $response['publicId']
            // external_ref = $externalRef
            // api_provisioned_at = now()
            // api_provision_error = null
        } catch (LebytekApiException $e) {
            // api_provision_error = $e->getMessage()
            throw $e;
        }
    }
}
```

---

## 6. Segundo correo (v1)

Tras aprobar lead, provisioning exitoso y (cuando exista en api) emisión de token por-tenant:

| Campo plantilla | v1 |
|-----------------|-----|
| Nombre cliente | Sí |
| Token Sanctum por-tenant | **Obligatorio** (vía `POST /tenants/{publicId}/tokens` — pendiente api) |
| Base URL api (`https://api.lebytek.com/api/v1`) | Recomendado |
| Enlace / login waapi | **Omitir** en v1 |
| Token Green API | **Prohibido** |

Pago manual (transferencia) lo gestiona lebytek.com **antes** del 2º correo.

---

## 7. Legacy Green path (disable)

Cuando api esté wired, desactivar provisioning local Green:

```env
GREEN_API_ENABLED=false
```

Referencia: `config/integrations.php`, `config/modules/integrations.php` — `DemoProvisioningService` / Partner local **off**.

---

## 8. Health check cron

Script PHP dedicado (no `php artisan`):

```bash
# crontab ejemplo — cada 5 min
*/5 * * * * cd /home/lebytek/htdocs/lebytek.com && php scripts/lebytek-api-health.php
```

`scripts/lebytek-api-health.php` instancia `LebytekApiClient` vía container y llama `health()`; loguea fallos sin imprimir token.

---

## 9. Manual E2E curl commands

```bash
export LEBYTEK_API_URL=https://api.lebytek.com/api/v1
export LEBYTEK_API_TOKEN=<token>

# Health
curl -sf -H "Authorization: Bearer $LEBYTEK_API_TOKEN" \
  -H "Accept: application/json" \
  "$LEBYTEK_API_URL/health"

# Provision test
curl -sf -X POST "$LEBYTEK_API_URL/tenants" \
  -H "Authorization: Bearer $LEBYTEK_API_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Idempotency-Key: $(uuidgen 2>/dev/null || powershell -c '[guid]::NewGuid()')" \
  -d '{"name":"Test Lead","slug":"test-lead-e2e","externalRef":"lebytek_lead_e2e_test"}'

# Idempotencia — repetir mismo body → 200 mismo publicId
```

En back-office: aprobar lead de prueba → verificar fila con `api_tenant_public_id` NOT NULL.

---

## 10. Checklist

- [ ] `.env` con `LEBYTEK_API_URL` + `LEBYTEK_API_TOKEN`
- [ ] Migración columnas api en `dom_mkt_leads`
- [ ] `LebytekApiClient` con Idempotency-Key y retry 429/5xx
- [ ] `LebytekApiException` con `statusCode` y `errors`
- [ ] `LeadApiProvisioningService` hook al aprobar lead
- [ ] Script `scripts/lebytek-api-health.php` + cron
- [ ] Plantilla 2º correo (token + base URL; sin waapi)
- [ ] `GREEN_API_ENABLED=false` cuando api wired
- [ ] Logs sin token en claro
- [ ] Grep/CI sin referencias `green-api.com` en `app/`

---

## 11. Prohibiciones

```
❌ curl/api.green-api.com
❌ Guardar GREEN_API_TOKEN en BD back-office
❌ Recibir webhooks Green directamente
❌ Duplicar colas Redis de campañas
❌ Commitear LEBYTEK_API_TOKEN
❌ Illuminate\Support\Str / Eloquent en cliente api
```

---

## 12. Referencias api (código fuente)

| Qué | Archivo en WhatsApiLebytek |
|-----|---------------------------|
| Rutas | `routes/api.php` |
| Provisioning | `app/Services/TenantProvisioningService.php` |
| Controller | `app/Http/Controllers/Api/V1/TenantController.php` |
| Idempotency HTTP | `app/Http/Middleware/ApiIdempotencyKey.php` |
| Acting tenant | `app/Http/Middleware/ResolveActingTenant.php` |
| Token CLI | `app/Console/Commands/IssueWaapiTokenCommand.php` |
| Tests contrato | `tests/Feature/Api/TenantProvisioningTest.php` |

---

## 13. Prompt listo para Cursor (repo Lebytek_Framework)

```
Implementa la integración con api.lebytek.com siguiendo EXACTAMENTE
docs/integration/lebytek-implementation-real.md.

Entregables:
1. LebytekApiClient (curl) con Bearer, Idempotency-Key, retry 429/5xx
2. Migración dom_mkt_leads (api_tenant_public_id, external_ref, api_provisioned_at)
3. LeadApiProvisioningService llamado al aprobar lead
4. Script scripts/lebytek-api-health.php + cron
5. Variables .env documentadas en README

Prohibido: green-api.com, webhooks Green, colas de campañas, guardar tokens Green, Laravel facades.

Contrato fase 1: GET /health, POST/GET/PATCH /tenants.
```
