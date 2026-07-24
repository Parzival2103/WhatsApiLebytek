# Spec — Barra de uso de mensajes en demos (admin MKT leads)

**Fecha:** 2026-07-23  
**Repos:** `WhatsApiLebytek` (api) + `Lebytek_Framework` (admin / waapi)  
**Estado:** Spec pendiente de review del usuario  
**Plan:** [`docs/superpowers/plans/2026-07-23-admin-leads-message-usage-progress.md`](../plans/2026-07-23-admin-leads-message-usage-progress.md)

**Contexto:** En el admin, “Demos activas” abre `/admin/crud/mkt_leads?estado=demo_enviada`. La columna “Última actividad” (`last_activity_at`) está vacía porque nadie la escribe. Se necesita una columna nueva de alta prioridad con barra de progreso `enviados / límite del plan` (ej. `20 / 100`).

---

## 1. Problema

Operadores no ven de un vistazo cuánto de la cuota mensual ha consumido cada demo. El portal público ya muestra uso vía `POST /account/status` (token de tenant); el admin CRUD no tiene ese dato y el token de tenant no se guarda en `dom_mkt_leads`.

### Lo que no se debe romper

- Columna `last_activity_at` / “Última actividad” (se mantiene).
- Escapado HTML del resto de celdas CRUD.
- Contrato existente de `POST /account/status` y `GET /usage`.
- Flujo de provision/deprovision de demos.
- Merge de `feature/backoffice-api-integration` → `main` en Framework (fuera de alcance).

---

## 2. Decisiones

| Decisión | Valor |
|----------|--------|
| Columna | **Nueva** `messages_usage` (“Uso mensajes”), `priority: 1`; no reemplaza “Última actividad” |
| Fuente de datos | Endpoint **platform-scoped** en api (no token de tenant) |
| Forma de fetch en listado | **Batch**: 1 request HTTP por página de listado |
| Semáforo | Verde &lt;70%, amarillo 70–89%, rojo ≥90% |
| Sin tenant / límite `null` / error API | Mostrar `—` (listado no falla) |
| Texto en barra | `"{sent} / {limit}"` (ej. `20 / 100`) |
| Conteos | Misma regla que cuota: outbound `queued`+`sent` del mes calendario (`AccountStatusService`) |
| GET individual | Opcional (detalle/debug); el enrich del CRUD usa solo batch |
| Cache Redis | Fuera de alcance v1 |

---

## 3. Enfoques considerados

| # | Enfoque | Pros | Contras |
|---|---------|------|---------|
| A-naive | `GET /tenants/{id}/usage` × N filas | Simple | N round-trips (100 demos → 100 HTTP) |
| **A-batch (elegido)** | `POST /tenants/usage` con lista de publicIds + GET single opcional | 1 round-trip/página; datos frescos | Un endpoint batch más |
| B | Cache en `dom_mkt_leads` + cron/webhook | Lista sin llamar api | Desfase; más infra |
| C | Meter usage en `TenantResource` | Menos paths | Ensucia getTenant; no resuelve N calls |

Recomendación: **A-batch**.

---

## 4. Diseño técnico — API (WhatsApiLebytek)

### 4.1 `GET /api/v1/tenants/{publicId}/usage` (opcional / helper)

- Auth: Bearer Sanctum  
- Permission: `tenants.ver` (mismo criterio que `GET /tenants/{publicId}`)  
- Acceso: **plataforma** (cualquier tenant) **o** usuario del mismo tenant  
- Resuelve tenant por `publicId` (ULID)

**200:**

```json
{
  "messagesSentThisMonth": 20,
  "messagesLimitThisMonth": 100,
  "messagesRemainingThisMonth": 80
}
```

Si `messages_monthly_limit` es `null`:

```json
{
  "messagesSentThisMonth": 20,
  "messagesLimitThisMonth": null,
  "messagesRemainingThisMonth": null
}
```

Errores: `404` tenant inexistente; `403` sin permiso / acceso denegado (no plataforma y no mismo tenant).

### 4.2 `POST /api/v1/tenants/usage` (batch — usado por admin)

```http
POST /api/v1/tenants/usage
Authorization: Bearer <platform token>
Content-Type: application/json
Idempotency-Key: <uuid nuevo por llamada>

{
  "publicIds": ["01JXYZ...", "01JABC..."]
}
```

- Permission: `tenants.ver`; **solo plataforma**  
- Validación: `publicIds` array de strings **ULID**, **mín. 1, máx. 100**, únicos  
- Implementación: reutilizar conteo de `AccountStatusService` (extraer método compartido si hace falta). **No** usar `TenantUsageService` (Redis metering).  
- IDs desconocidos: **omitidos** del map (no error 404 del request entero)  
- Idempotency-Key: requerido por middleware v1; mint **nuevo** key por enrich (cache 24h)

**200:**

```json
{
  "items": {
    "01JXYZ...": {
      "messagesSentThisMonth": 20,
      "messagesLimitThisMonth": 100,
      "messagesRemainingThisMonth": 80
    },
    "01JABC...": {
      "messagesSentThisMonth": 0,
      "messagesLimitThisMonth": null,
      "messagesRemainingThisMonth": null
    }
  }
}
```

### 4.3 Archivos previstos (api)

| Pieza | Ubicación aproximada |
|-------|----------------------|
| Rutas | `routes/api.php` |
| Controller | `app/Http/Controllers/Api/V1/TenantUsageController.php` (o método en TenantController) |
| Request batch | `app/Http/Requests/Api/V1/TenantUsageBatchRequest.php` |
| Lógica | Extraer/reusar desde `AccountStatusService` |
| Tests | `tests/Feature/Api/…TenantUsage…` |
| Contrato | `docs/integration/waapi-api-contract.md` |

---

## 5. Diseño técnico — Framework (admin CRUD)

### 5.1 Columna

En `config/cruds/mkt_leads.json`, dentro de `list.columns`, añadir (cerca del inicio / alta prioridad):

```json
{
  "name": "messages_usage",
  "label": "Uso mensajes",
  "format": "progress",
  "priority": 1,
  "virtual": true
}
```

Campo **virtual** (`"virtual": true` — no columna BD; el CRUD Engine debe omitirlo del SELECT). “Última actividad” se mantiene con `priority: 5`.

### 5.2 Formato `progress` (plataforma `src/`)

`CrudTableBuilder::formatRow`:

- Si valor vacío / no array / `limit` null → `_formatted[name] = '—'`
- Si no: `pct = min(100, floor(sent * 100 / limit))`
  - `pct < 70` → clase Bootstrap `success`
  - `70 <= pct < 90` → `warning`
  - `pct >= 90` → `danger`
- Si `sent > limit`: barra 100%, texto real `"{sent} / {limit}"`, clase `danger`
- Generar HTML en `_html[name]` (solo enteros + clases whitelist); no confiar en HTML entrante

Vista `src/Presentation/Views/admin/crud/index.php`:

- Si existe `_html[$name]`, imprimir sin `ViewHelper::e` (HTML ya construido en builder)
- Resto de celdas: sin cambio (siguen escapadas)

### 5.3 Hook `afterListRows`

Hoy `beforeListQuery` existe solo como no-op/docblock y **no está wired**. Añadir hook extendido (mismo patrón `method_exists` / Abstract no-op; **no** añadir método al contrato Create/Update/Delete de la interface):

- Firma: `afterListRows(CrudListRowsContext $ctx): void`
- Invocado **después** de obtener filas de BD y **antes** de `CrudTableBuilder::build` (primer call-site de list hooks en `CrudResourceService`)
- Contexto permite leer/mutar la lista de rows de la página actual

Handler app: clave `mkt_leads` en `config/crud_handlers.php` → clase en `app/Application/Marketing/` (o Infrastructure) que:

1. Recolecta `api_tenant_public_id` no vacíos de las filas
2. Si vacío → no llama api
3. `LebytekApiClient::getTenantsUsage(array $publicIds)` → `POST /tenants/usage`
4. Asigna `messages_usage = ['sent' => …, 'limit' => …]` o deja null → `—`
5. Ante excepción/timeout de api: deja null (`—`); no lanza
6. Cliente de enrich: timeout corto (default 5s) y `maxRetries: 1` — no reusar defaults de provisioning (30s×3)

### 5.4 Cliente

`LebytekApiClient::getTenantsUsage(array $publicIds): array`  
(opcional: `getTenantUsage(string $publicId)` wrapping GET single)

Sincronizar contrato en `docs/integration/waapi-api-contract.md` del Framework si existe copia.

### 5.5 Archivos previstos (Framework)

| Pieza | Ubicación aproximada |
|-------|----------------------|
| Columna CRUD | `config/cruds/mkt_leads.json` |
| Handler registry | `config/crud_handlers.php` |
| Enrich handler | `app/Application/Marketing/…` |
| API client | `app/Infrastructure/Integrations/LebytekApi/LebytekApiClient.php` |
| Hook + context | `src/Application/Crud/…`, `CrudDataService` / `CrudResourceService` |
| Progress format | `src/Application/Services/CrudTableBuilder.php` |
| Vista celdas | `src/Presentation/Views/admin/crud/index.php` |
| Tests | `tests/…` progress + enrich mock |

---

## 6. Edge cases

| Caso | Comportamiento |
|------|----------------|
| Sin `api_tenant_public_id` | `—` |
| `messagesLimitThisMonth === null` | `—` |
| API caída / timeout / 5xx | `—`; listado 200 |
| publicId no encontrado en batch | omitido → `—` |
| `sent > limit` | barra 100%, texto real, `danger` |
| Página sin tenants | no HTTP a api |
| Más de 100 IDs en una página | chunk en lotes de 100 en el client (defensa); page size CRUD típico &lt; 100 |

---

## 7. Fuera de alcance

- Escribir `last_activity_at` / `first_message_sent_at` desde webhooks
- Cache Redis / TTL de usage
- Cambiar UI del portal público waapi
- Endpoint batch con más de 100 IDs sin chunking
- Merge Framework → `main`

---

## 8. Criterios de aceptación

1. En `/admin/crud/mkt_leads?estado=demo_enviada`, aparece “Uso mensajes” con prioridad visual alta.
2. Demo con límite 100 y 20 enviados muestra barra ~20% verde y texto `20 / 100`.
3. Al 75% → amarillo; al 95% → rojo.
4. Lead sin tenant o plan ilimitado → `—`.
5. Una página con N demos genera **1** (o ceil(N/100)) llamada(s) batch a api, no N GETs.
6. Si api no responde, el listado carga y las celdas de uso son `—`.
7. Tests Feature (api) + tests Framework (progress + enrich) en verde.
8. Contrato documentado en `waapi-api-contract.md` (api; mirror Framework si aplica).

---

## 9. Self-review

- [x] Sin placeholders TBD en decisiones clave  
- [x] Batch elimina contradicción N+1 vs “enfoque A”  
- [x] Alcance acotado (sin cache, sin last_activity)  
- [x] Permiso y auth platform explícitos  
- [x] HTML progress solo desde enteros + clases fijas (XSS)  
