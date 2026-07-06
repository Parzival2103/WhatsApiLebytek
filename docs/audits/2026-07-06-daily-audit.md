# Auditoría técnica diaria — 2026-07-06

**Repo:** Parzival2103/WhatsApiLebytek  
**Rama revisada:** `main` @ `a454121`  
**Auditor:** Cloud Agent (cron)

---

## Resumen ejecutivo

El repositorio lleva **5 días sin commits** en `main`. La integración Fase 2a (`/messages`) está implementada en código con tests Pest escritos, pero **el go-live sigue bloqueado** por smoke VPS pendiente y hallazgos estructurales sin resolver.

**Riesgo global: ALTO** por incompatibilidad de autenticación de webhooks Green API (las instancias probablemente no transicionan a `authorized` en producción vía webhook) y **CI roto** desde el 2026-07-02.

No se ejecutaron tests localmente (PHP no disponible en el entorno del agente). GitHub Actions confirma fallo en `composer install`.

---

## Hallazgos críticos

### 1. Webhooks Green API — autenticación incompatible

| Componente | Implementación actual | Green API real |
|------------|----------------------|----------------|
| Auth | HMAC `X-Webhook-Signature` sobre body (`VerifyWebhookSignature`) | `Authorization: Bearer {webhookUrlToken}` |
| Idempotencia | Header obligatorio `X-Event-Id` (`WebhookIdempotency`) | No documentado por Green API |
| Config provisioning | `webhookUrlToken` = `WEBHOOK_SECRET` (`ProvisionGreenInstanceJob`) | Token enviado como Bearer, no HMAC |

**Impacto:** `stateInstanceChanged` → `authorized` no se procesa; smoke paso 2 (QR) y paso 3 (`POST /messages`) fallan en cadena.

**Archivos:** `app/Http/Middleware/VerifyWebhookSignature.php`, `WebhookIdempotency.php`, `app/Jobs/ProvisionGreenInstanceJob.php`, `tests/Feature/Webhooks/WebhookVerificationTest.php`

### 2. CI GitHub Actions roto (PHP 8.3 vs Symfony 8.1)

- `composer.lock` resuelve `symfony/http-foundation` v8.1.1 → requiere **PHP ≥ 8.4.1**
- Workflow `.github/workflows/tests.yml` usa PHP **8.3**
- Último run `main`: **failure** (2026-07-02, run `28568614506`)

**Impacto:** Ningún PR puede validarse automáticamente; regresiones no detectadas.

### 3. Emisión token tenant — abilities `mensajes.*` bloqueadas

- Contrato (`waapi-api-contract.md` L41): token demo debe incluir `mensajes.enviar`, `mensajes.ver`
- `StoreTenantTokenRequest` solo permite `abilities.*` ∈ `['instancias.ver']`
- Default en `TenantTokenService` y `TenantController`: `['instancias.ver']`

**Impacto:** Si Framework (R1.7) envía abilities ampliadas → **422**. Si no las envía → cliente no puede `POST /messages` (403).

**Archivos:** `app/Http/Requests/Api/V1/StoreTenantTokenRequest.php`, `app/Services/TenantTokenService.php`, `app/Http/Controllers/Api/V1/TenantController.php`

---

## Hallazgos medios

1. **Rate limiting API documentado pero no aplicado** — `RateLimiter::for('api')` en `AppServiceProvider` sin `throttle:api` en `routes/api.php`; contrato promete 429 a 60 req/min.
2. **`int_webhooks` sin uso** — migración creada; `IncomingWebhookController` no persiste eventos (deuda R1/YAGNI documentada).
3. **Documentación desactualizada** — `docs/superpowers/specs/2026-07-02-integration-phase4-5-design.md` §0 marca `/messages` y migraciones como ❌; ya implementados en `dcc46d0`.
4. **Variables `.env` redundantes** — `GREEN_API_WEBHOOK_TOKEN` en `.env.example` no referenciado; `WEBHOOK_SECRET` es el canónico.
5. **Go-live bloqueado** — `VPS_CHECKLIST.md`: smoke mensaje móvil (2a paso 3), crons operador, DNS cutover pendientes.
6. **Doble idempotencia en `/messages`** — middleware `ApiIdempotencyKey` + `payload_hash` en `MessageSendService` (defensa en profundidad aceptable, pero comportamiento ante reintentos con distinto body no documentado).

---

## Mejoras rápidas (bajo riesgo)

| # | Cambio | Archivo(s) | Esfuerzo |
|---|--------|------------|----------|
| 1 | Subir CI a PHP 8.4 + alinear `composer.json` `"php": "^8.4"` | `.github/workflows/tests.yml`, `composer.json` | Mínimo |
| 2 | Ampliar `StoreTenantTokenRequest` abilities a `instancias.ver`, `mensajes.enviar`, `mensajes.ver` + default demo | `StoreTenantTokenRequest.php`, tests | Bajo |
| 3 | Añadir `throttle:api` al grupo API | `bootstrap/app.php` o `routes/api.php` | Bajo |
| 4 | Banner "histórico" en spec phase4-5 §0 o actualizar tabla | `docs/superpowers/specs/2026-07-02-integration-phase4-5-design.md` | Docs only |

---

## Riesgos de deploy (VPS)

| Riesgo | Severidad | Nota |
|--------|-----------|------|
| `php artisan migrate --force` sin ejecutar tras pull | Alta | Migraciones `int_mensajes`, `int_webhooks` requeridas para `/messages` |
| Webhooks 401/422 en producción | **Crítica** | QR puede funcionar por polling manual; autorización automática no |
| Horizon cola `transactional` | Media | Verificar supervisor tras deploy |
| PHP VPS posiblemente 8.3 | Alta | Si VPS tiene 8.3, `composer install` también fallará (mismo error que CI) |
| Crons health/expire demos sin confirmar | Media | `VPS_CHECKLIST.md` R2 |
| Registro web abierto en prod | Baja | Mitigado: `routes/auth.php` redirige `/register` en production |

---

## Tests faltantes / gaps

- Test integración webhook con formato **Bearer** Green API (actual solo HMAC fake)
- Test `POST /tenants/{id}/tokens` con abilities `mensajes.*`
- Test rate limit 429 en API
- E2E smoke VPS documentado pero no automatizado en CI

**Cobertura existente relevante:** `MessageSendTest`, `InstanceProvisioningTest`, `TenantIsolationTest`, `DefaultDenyTest`, `WebhookVerificationTest` (formato incorrecto vs producción).

---

## Cambios recientes Git (7 días)

47 commits; highlights:

| Commit | Área |
|--------|------|
| `a454121` | Credentials stub 501, prod register guard, portal docs |
| `dcc46d0` | **POST/GET /messages** MVP, migraciones, jobs |
| `c9b1bc2` | Green API instances, tenant tokens |
| `4d6f9f8` | DeleteGreenInstanceJob fail on partner error |

**Últimas 24h:** sin commits.

---

## Archivos involucrados (índice)

```
routes/api.php
routes/auth.php
app/Http/Middleware/VerifyWebhookSignature.php
app/Http/Middleware/WebhookIdempotency.php
app/Http/Requests/Api/V1/StoreTenantTokenRequest.php
app/Http/Controllers/Api/V1/MessageController.php
app/Http/Controllers/Api/V1/IncomingWebhookController.php
app/Jobs/ProvisionGreenInstanceJob.php
app/Jobs/TransactionalMessageJob.php
config/permissions.php
database/migrations/2026_07_02_100000_create_int_mensajes_table.php
database/migrations/2026_07_02_100001_create_int_webhooks_table.php
.github/workflows/tests.yml
composer.json / composer.lock
docs/integration/VPS_CHECKLIST.md
docs/integration/waapi-api-contract.md
```

---

## Recomendación final

**`crear issue`** (manual — token automation sin permiso `issues`) + **`requiere revisión humana`**

Prioridad issue #1: alinear middleware webhooks con Green API Bearer + idempotencia por payload/hash.  
Prioridad issue #2: CI PHP 8.4 (PR de bajo riesgo aceptable).  
Prioridad issue #3: abilities token demo antes de smoke VPS mensaje.

**Sin acción automática en código** en esta auditoría (hallazgos medio/alto).
