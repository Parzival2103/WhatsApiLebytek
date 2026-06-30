# waapi — implementación real (espejo del contrato api)

Guía **operativa y concreta** para implementar en **Lebytek_Framework** (`waapi.lebytek.com`) lo que api ya expone y exige. Complementa el contrato abstracto en [`waapi-api-contract.md`](waapi-api-contract.md).

**Auditoría previa:** [`prompt2-review-pre-waapi.md`](prompt2-review-pre-waapi.md)

---

## 1. Qué ya existe en api (no reimplementar en waapi)

| Capacidad | Dónde vive en api | waapi hace |
|-----------|-------------------|------------|
| Crear tenant técnico | `POST /api/v1/tenants` | Orquestar + guardar `publicId` |
| Idempotencia tenant | `externalRef` + `core_tenants.external_ref` | Enviar `waapi_org_{id}` |
| Idempotencia HTTP | Middleware `ApiIdempotencyKey` | Header `Idempotency-Key` en POST/PATCH |
| RBAC / permisos | Spatie en api | Solo Bearer token; sin RBAC local de api |
| Multi-tenant datos WhatsApp | `BelongsToTenant` + Redis colas | Enviar `X-Tenant-Id` en fase 2 |
| Green API | `int_credenciales`, jobs, webhooks | **Nada** |
| Health motor | `GET /api/v1/health` | Monitoreo periódico |

---

## 2. Variables de entorno waapi

```env
# Obligatorias fase 1
LEBYTEK_API_URL=https://api.lebytek.com/api/v1
LEBYTEK_API_TOKEN=           # Sanctum plain token — NUNCA en git

# Recomendadas
LEBYTEK_API_TIMEOUT=30
LEBYTEK_API_RETRY_MAX=3
LEBYTEK_API_RETRY_DELAY_MS=500
```

**Obtener token (una vez en VPS api):**

```bash
cd /home/lebytek-api/htdocs/api.lebytek.com
sudo -u lebytek-api php artisan integration:issue-waapi-token --revoke
```

Copiar salida → `LEBYTEK_API_TOKEN` en waapi. Rotar periódicamente con `--revoke`.

---

## 3. Migración BD waapi

Adaptar nombre de tabla si el skeleton usa otro (ej. `empresas`, `orgs`):

```sql
ALTER TABLE organizations
  ADD COLUMN api_tenant_public_id CHAR(26) NULL,
  ADD COLUMN external_ref VARCHAR(255) NULL,
  ADD COLUMN api_provisioned_at TIMESTAMP NULL,
  ADD COLUMN api_provision_error TEXT NULL,
  ADD UNIQUE KEY organizations_api_tenant_public_id_unique (api_tenant_public_id),
  ADD UNIQUE KEY organizations_external_ref_unique (external_ref);
```

| Columna | Valor |
|---------|-------|
| `external_ref` | `waapi_org_{organizations.id}` — **mismo** string en cada llamada |
| `api_tenant_public_id` | `publicId` ULID de la respuesta api |
| `api_provisioned_at` | `NOW()` al recibir 200/201 exitoso |
| `api_provision_error` | Último error JSON/texto si falló (para soporte) |

---

## 4. Cliente HTTP — implementación PHP concreta

**Ubicación sugerida:** `app/Integrations/LebytekApi/LebytekApiClient.php`

### 4.1 Headers que api exige

| Operación | Headers |
|-----------|---------|
| Todas | `Authorization: Bearer {token}`, `Accept: application/json` |
| POST/PATCH | `Content-Type: application/json`, `Idempotency-Key: {uuid}` |
| Fase 2 tenant-scoped | `X-Tenant-Id: {api_tenant_public_id}` |

### 4.2 Código base (copiar/adaptar al skeleton)

```php
<?php

namespace App\Integrations\LebytekApi;

use Illuminate\Support\Str;

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
        return $this->request('GET', "/tenants/{$publicId}");
    }

    public function updateTenant(string $publicId, array $payload): array
    {
        return $this->request('PATCH', "/tenants/{$publicId}", $payload);
    }

    public function listTenants(int $page = 1, int $perPage = 15): array
    {
        return $this->request('GET', '/tenants?page='.$page.'&perPage='.$perPage);
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
            $baseHeaders[] = 'Idempotency-Key: '.Str::uuid()->toString();
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

    private function tenantHeaders(?string $actingTenantPublicId): array
    {
        if ($actingTenantPublicId === null || $actingTenantPublicId === '') {
            return [];
        }

        return ['X-Tenant-Id: '.$actingTenantPublicId];
    }
}
```

```php
<?php

namespace App\Integrations\LebytekApi;

final class LebytekApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?array $errors = null,
    ) {
        parent::__construct($message, $statusCode);
    }
}
```

### 4.3 Registro en contenedor / config skeleton

```php
// Ejemplo binding (adaptar al DI del framework Lebytek)
$container->singleton(LebytekApiClient::class, fn () => new LebytekApiClient(
    baseUrl: env('LEBYTEK_API_URL'),
    token: env('LEBYTEK_API_TOKEN'),
    timeoutSeconds: (int) env('LEBYTEK_API_TIMEOUT', 30),
    maxRetries: (int) env('LEBYTEK_API_RETRY_MAX', 3),
));
```

---

## 5. Servicio de provisioning — flujo exacto

**Ubicación sugerida:** `app/Services/OrganizationApiProvisioningService.php`

```php
<?php

namespace App\Services;

use App\Integrations\LebytekApi\LebytekApiClient;
use App\Integrations\LebytekApi\LebytekApiException;
use App\Models\Organization;

final class OrganizationApiProvisioningService
{
    public function __construct(private readonly LebytekApiClient $api) {}

    public function provision(Organization $org): void
    {
        if ($org->api_tenant_public_id) {
            return; // ya provisionado
        }

        $externalRef = $org->external_ref ?? 'waapi_org_'.$org->id;
        $slug = $org->slug ?? $this->slugify($org->name).'-'.$org->id;

        try {
            $response = $this->api->provisionTenant(
                name: $org->name,
                slug: $slug,
                externalRef: $externalRef,
            );

            $org->update([
                'external_ref' => $externalRef,
                'api_tenant_public_id' => $response['publicId'],
                'api_provisioned_at' => now(),
                'api_provision_error' => null,
            ]);
        } catch (LebytekApiException $e) {
            $org->update([
                'external_ref' => $externalRef,
                'api_provision_error' => json_encode([
                    'status' => $e->statusCode,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors,
                ]),
            ]);
            throw $e;
        }
    }

    private function slugify(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)) ?? 'org');
    }
}
```

### Cuándo llamar

| Evento | Acción |
|--------|--------|
| Post-registro (crear Organization) | `provision()` síncrono o job en cola |
| Login si `api_tenant_public_id` null | Reintentar `provision()` |
| Comando cron `organizations:reconcile-api` | Re-provisionar orgs pendientes |

---

## 6. Respuestas api — qué parsear

### `POST /tenants` — éxito

```json
{
  "publicId": "01J8K3M2N4P5Q6R7S8T9V0W1X2",
  "name": "Acme Corp",
  "slug": "acme-corp",
  "externalRef": "waapi_org_42",
  "isActive": true,
  "createdAt": "2026-06-29T12:00:00+00:00",
  "updatedAt": "2026-06-29T12:00:00+00:00"
}
```

| HTTP | Significado | Acción waapi |
|------|-------------|--------------|
| 201 | Tenant nuevo | Guardar `publicId` |
| 200 | `externalRef` ya existía | Guardar `publicId` (mismo) |
| 401 | Token inválido | Alerta ops; revisar `LEBYTEK_API_TOKEN` |
| 403 | Sin permiso | Token revocado o usuario servicio mal configurado |
| 422 | Validación (slug duplicado, etc.) | Corregir slug; **no** cambiar `externalRef` |
| 429 | Rate limit | Retry con backoff |

### `GET /health`

```json
{
  "status": "ok",
  "checks": {
    "database": { "ok": true, "message": "connected" },
    "redis": { "ok": true, "message": "connected" }
  },
  "timestamp": "2026-06-29T12:00:00+00:00",
  "actingTenant": null
}
```

Comando cron sugerido:

```bash
# waapi — cada 5 min
php artisan lebytek:health-check
```

Fallo si `status !== 'ok'` o cualquier `checks.*.ok === false` → log + alerta (no afecta UX usuario).

---

## 7. Medidas de seguridad waapi (espejo de api)

| Medida en api | Implementación equivalente en waapi |
|---------------|-------------------------------------|
| Token Sanctum secreto | `LEBYTEK_API_TOKEN` en `.env` solo; permisos archivo 600 |
| Idempotency-Key | UUID nuevo por request POST/PATCH (cliente arriba) |
| Idempotencia negocio `externalRef` | Columna `external_ref` estable `waapi_org_{id}` |
| No exponer Green API | Grep CI: `green-api.com` prohibido en `app/` |
| No loguear token | En logs usar `Authorization: [REDACTED]` |
| Rate limit 60/min | Cliente con retry en 429; no hacer loops tight |
| ULID público | Guardar `api_tenant_public_id`; nunca usar PK local como tenant api |
| X-Tenant-Id fase 2 | Método helper `withTenant($org)` que añade header |

### Prohibiciones absolutas en waapi

```
❌ curl/api.green-api.com
❌ Guardar GREEN_API_TOKEN en BD waapi
❌ Recibir webhooks Green directamente
❌ Duplicar colas Redis de campañas
❌ Commitear LEBYTEK_API_TOKEN
```

---

## 8. Comando de reconciliación (orgs sin provisionar)

```php
<?php

// app/Console/Commands/ReconcileOrganizationApiCommand.php
// php artisan organizations:reconcile-api

foreach (Organization::whereNull('api_tenant_public_id')->cursor() as $org) {
    try {
        app(OrganizationApiProvisioningService::class)->provision($org);
        $this->info("Provisioned org {$org->id}");
    } catch (\Throwable $e) {
        $this->error("Failed org {$org->id}: {$e->getMessage()}");
    }
}
```

---

## 9. Prueba manual E2E (antes de merge waapi)

```bash
# 1. Desde máquina con token válido
export LEBYTEK_API_URL=https://api.lebytek.com/api/v1
export LEBYTEK_API_TOKEN=<token>

# 2. Health
curl -sf -H "Authorization: Bearer $LEBYTEK_API_TOKEN" \
  "$LEBYTEK_API_URL/health" | jq .

# 3. Provision test
curl -sf -X POST "$LEBYTEK_API_URL/tenants" \
  -H "Authorization: Bearer $LEBYTEK_API_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen 2>/dev/null || powershell -c '[guid]::NewGuid()')" \
  -d '{"name":"Test WA","slug":"test-wa-'.$(date +%s)'","externalRef":"waapi_org_e2e_test"}' | jq .

# 4. Idempotencia — repetir mismo body → 200 mismo publicId
```

En waapi: registrar org de prueba → verificar fila con `api_tenant_public_id` NOT NULL.

---

## 10. Fase 2 — preparar sin implementar aún

Cuando api publique endpoints WhatsApp en OpenAPI:

```php
// Patrón futuro — NO implementar hasta que exista en /docs
$this->api->request('POST', '/messages', $payload, headers: [
    'X-Tenant-Id: '.$org->api_tenant_public_id,
]);
```

waapi solo muestra UI; api ejecuta envío.

---

## 11. Checklist implementación waapi

- [ ] `.env` con `LEBYTEK_API_URL` + `LEBYTEK_API_TOKEN`
- [ ] Migración columnas `api_tenant_public_id`, `external_ref`, `api_provisioned_at`
- [ ] `LebytekApiClient` con Idempotency-Key y retry 429/5xx
- [ ] `LebytekApiException` con `statusCode` y `errors`
- [ ] `OrganizationApiProvisioningService` hook post-registro
- [ ] `organizations:reconcile-api` comando cron
- [ ] `lebytek:health-check` comando cron
- [ ] Logs sin token en claro
- [ ] Test integración (mock HTTP o sandbox api)
- [ ] Grep/CI sin referencias `green-api.com`

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

## 13. Prompt listo para Cursor (repo waapi)

```
Implementa la integración con api.lebytek.com siguiendo EXACTAMENTE
docs/integration/waapi-implementation-real.md del repo WhatsApiLebytek.

Entregables:
1. LebytekApiClient (curl o Guzzle del skeleton) con Bearer, Idempotency-Key, retry 429/5xx
2. Migración organizations (api_tenant_public_id, external_ref, api_provisioned_at)
3. OrganizationApiProvisioningService llamado al crear organization
4. Comandos organizations:reconcile-api y lebytek:health-check
5. Variables .env documentadas en README

Prohibido: green-api.com, webhooks Green, colas de campañas, guardar tokens Green.

Contrato fase 1: GET /health, POST/GET/PATCH /tenants.
```
