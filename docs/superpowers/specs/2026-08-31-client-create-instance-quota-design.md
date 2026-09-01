# Client create instance + plan quota

**Date:** 2026-08-31  
**Repo:** WhatsApiLebytek (`api.lebytek.com`)  
**Status:** Approved design — pending implementation  
**Related:** `2026-06-29-green-api-partner-instances-design.md`, `2026-07-14-plan-activation-and-package-limits-design.md`

## Problem

Hoy:

- Portal/admin crea **solo la 1ª** instancia con token de plataforma (`ensureAtLeastOneInstance`).
- `POST /api/v1/instances` es **solo plataforma** (`InstanceController::store` → `ensurePlatformService`).
- Tokens por-tenant (`demo_client_abilities`) tienen `instancias.ver` pero **no** `instancias.crear`.
- `config/plans.php` / `PlanCatalog` limitan mensajes y rate; **no** tienen `max_instances`.
- El cupo comercial ya se vende en Portal (`dom_mkt_paquetes.features`: demo/starter=1, business=3, empresa=custom) pero la API no lo enforce.

Resultado: un cliente con plan business no puede crear la 2ª/3ª WhatsApp con su Bearer; multi-instance quedó out of scope en activate-plan y este trabajo lo cierra.

## Goal

Un cliente autenticado con **Bearer Sanctum por-tenant** (ability `instancias.crear`) puede:

```http
POST /api/v1/instances
Authorization: Bearer {token_cliente}
Idempotency-Key: {uuid}
Content-Type: application/json

{
  "label": "WhatsApp Sucursal 2",
  "externalRef": "opcional-idempotente",
  "purpose": "production"
}
```

- Si `count(instancias del tenant) < max_instances` → mismo flujo async actual (**202** + job Green).
- Si alcanzó el cupo → **422** con mensaje claro en español orientado a upgrade (Portal podrá disparar correo + link de pago).
- Platform Bearer + `X-Tenant-Id` sigue pudiendo crear; **mismo cupo** (sin bypass). Ops sube plan o override de `max_instances` en empresa.
- `POST /account/status` expone `instances: { used, limit }` para sandbox/UI futuras.

## Non-goals (esta PR API)

- UI en Portal (`waapi.lebytek.com`) — solo nota en el PR.
- Copy de landing / checkout de upgrade — solo el mensaje 422.
- Cambios en **docsV2** (sandbox UI, `lebytekApi.ts`, OpenAPI publicado allí) — follow-up documentado.
- Nombrar Green API en textos de cliente.
- Soft-delete de instancias como producto (si no existe hoy, no inventarlo).

## Decisions (aprobadas)

| Tema | Decisión |
|------|----------|
| HTTP cupo agotado | **422** + mensaje upgrade (Portal → correo + link pago) |
| HTTP sin ability | **403** (middleware `permission:instancias.crear`, sin cambio de semántica) |
| Persistencia del cupo | Columna **`max_instances`** nullable en `core_tenants` (paralelo a `messages_monthly_limit`) |
| Bypass plataforma | **No** — mismo cupo para platform y cliente |
| Conteo | Instancias del tenant **no soft-deleted**; **failed cuenta** hasta borrar o reintentar el mismo `externalRef` |
| Idempotencia | Hit por `externalRef` existente (no-failed o retry failed) **no** aplica el rechazo de cupo como “nueva” fila |
| `empresa` sin override | `max_instances = null` → **sin tope** |
| Tokens viejos | Sin `instancias.crear` → 403 RBAC; hace falta reemitir tras deploy (activate-plan / issue token / email membresía) |

## Catalog

En `config/plans.php`, por slug:

| `planSlug` | `max_instances` |
|------------|-----------------|
| `demo` | `1` |
| `starter` | `1` |
| `business` | `3` |
| `empresa` | `null` (ilimitado; override vía activate-plan body/`maxInstances`) |

`PlanCatalog` expone resolución (p. ej. `resolveMaxInstances(slug, ?override): ?int`) análoga a mensajes.

### Persistencia

- Migración: `core_tenants.max_instances` (`unsignedInteger` nullable).
- **Provision demo / create tenant:** setear desde catálogo del slug demo (1).
- **activate-plan:** setear desde catálogo del slug activado; si viene override (empresa / ops), validar y persistir ese valor.
- El guard lee **`$tenant->max_instances`**, no solo el slug en memoria. Si la columna es `null` → permitir (empresa / sin tope).

Backfill en migración (opcional pero recomendado): tenants existentes según `plan_slug` vía catálogo; `empresa` → `null`.

## Auth + store

1. Quitar `ensurePlatformService` de `InstanceController::store` (o reemplazar por: platform **o** usuario con tenant + ability — el middleware de permission ya exige `instancias.crear`).
2. Resolución de tenant:
   - Token tenant → su propio `tenant_id` (ignorar / no requerir `X-Tenant-Id` si el patrón actual de lecturas ya confina así; alinear con `resolveTenantAccess` / provisioning).
   - Platform → sigue exigiendo `X-Tenant-Id`.
3. Añadir `instancias.crear` a:
   - `config/permissions.php` → `demo_client_abilities`
   - Allowlist de `StoreTenantTokenRequest` (hoy bloquea pasarla)
4. `purpose`: `in:demo,production`. Default recomendado: `production` si `commercial_status=active`, si no `demo` (o mantener default request/`demo` documentado — implementar el default que ya use `StoreInstanceRequest` / service y documentarlo).
5. Flujo de creación (job, 202, recurso) **sin cambios** salvo el guard de cupo.

## Guard de cupo

En `InstanceProvisioningService::provision`, **después** de `WhatsappModuleGuard` y **antes** de crear una fila nueva:

1. Si hay `externalRef` y existe instancia → camino idempotente / retry actual (**no** fallar por cupo).
2. Si se va a **crear** nueva:
   - `limit = $tenant->max_instances`
   - Si `limit === null` → OK
   - `used = count` instancias del tenant (no soft-deleted)
   - Si `used >= limit` → excepción de dominio → HTTP **422** con mensaje:

   > Has alcanzado el límite de instancias WhatsApp de tu plan. Mejora tu cuenta para generar otra instancia.

Preferible excepción tipada (p. ej. `InstanceQuotaExceededException`) mapeada en handler/controller a 422 JSON consistente con el resto de la API.

## account/status

Extender payload de `AccountStatusService` / `AccountStatusController`:

```json
"instances": {
  "used": 1,
  "limit": 3
}
```

- `used`: mismo criterio de conteo que el guard.
- `limit`: `tenant.max_instances` (JSON `null` si ilimitado).

Requiere `cuenta.ver` (sin cambio de auth).

## Contrato + OpenAPI

Actualizar `docs/integration/waapi-api-contract.md` § `POST /instances`:

- Deja de ser “solo plataforma”.
- Documentar Bearer cliente + platform, cupos por plan, columna/persistencia, **422** upgrade, Idempotency-Key, body, abilities.
- Nota: tokens emitidos **antes** del deploy no tienen `instancias.crear` hasta reemisión.

Regenerar / alinear export OpenAPI del repo si el pipeline lo publica hacia docsV2; la UI sandbox de docsV2 es **follow-up**.

## Tests (obligatorio)

### Feature

- Tenant Bearer con `instancias.crear`, plan **business** (`max_instances=3`), 1 instancia existente → 2ª create → **202**.
- Mismo tenant / plan **starter** (`max_instances=1`) con 1 instancia → **422** + mensaje upgrade; sin fila nueva; sin dispatch de job Green.
- Sin ability `instancias.crear` → **403**.
- Platform + `X-Tenant-Id` crea dentro de cupo → **202**.
- Idempotencia por `externalRef`: segundo POST no rompe cupo / no crea fila extra.
- `POST /account/status` incluye `instances.used` / `instances.limit`.

### Unit

- `PlanCatalog` resuelve `max_instances` por slug (+ override empresa).

Actualizar test existente que aserte “tenant cannot create” (`InstanceProvisioningTest`) al nuevo comportamiento.

## Follow-ups (documentar en PR API; no implementar aquí)

### docsV2 (`Parzival2103/docsV2`)

- `src/lib/lebytekApi.ts` — allowlist POST `/instances` (+ opcional POST `account/status`); `createInstance(...)`.
- `DemoSandbox.tsx` — acción crear instancia; mostrar `used`/`limit`; no tomar solo `instances[0]` a ciegas.
- `src/data.ts`, OpenAPI/Postman, `tester.php` — alinear Bearer cliente + cupo.
- No nombrar Green en copy de cliente.

### Portal (`Lebytek_Portal`)

- Dashboard podría mostrar `instances.used` / `instances.limit`.
- Admin ops: listar instancias por `api_tenant_public_id` vía platform GET.
- **No** duplicar enforcement de cupo; la API es SoT.

## Acceptance criteria

1. curl Bearer cliente, tenant business con 1 instancia → crea 2ª → **202**.
2. Mismo flujo en starter con 1 instancia → **422** upgrade; sin fila nueva / sin job.
3. Contrato actualizado + tests verdes.
4. PR API incluye checklist explícita “pendiente docsV2” (y nota Portal).
5. Tokens nuevos (activate-plan / issue defaults) incluyen `instancias.crear`.

## Implementation order

1. Spec (este doc) → plan en `docs/superpowers/plans/`.
2. RED tests → GREEN: migración + catálogo + guard + auth/abilities + account/status.
3. Contrato + OpenAPI local.
4. PR API → deploy VPS → smoke curl.
5. Sesión/PR docsV2 aparte.
