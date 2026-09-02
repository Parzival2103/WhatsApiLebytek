---
type: process
status: verified
as_of: 2026-09-01
commit: f35560b
consumes: [tenant-max-instances, client-token-abilities, instancia]
produces: [instancia]
---

# create-instance

Client or platform creates a WhatsApp instance row and (usually) dispatches async Green provisioning.

## Input → Movement → Output

Bearer + Idempotency-Key + JSON body → auth + tenant resolve + module + **cupo** + idempotent externalRef → `Instancia` + optional job → HTTP 202/200 or **422**.

## Why this shape

Cupo lives in the service (not only controller) so every caller shares enforcement. externalRef replay/failed-retry must not burn a new cupo slot.

## Steps

1. Middleware: `auth:sanctum`, `permission:instancias.crear`, idempotency — `routes/api.php:62-64`
2. `InstanceController::store` uses `resolveTenantAccess` (no platform-only gate) — `InstanceController.php`
3. Default `purpose`: `production` if `commercial_status=active`, else `demo`
4. `InstanceProvisioningService::provision`: module guard → externalRef short-circuit → `ensureInstanceQuotaAvailable` → create + `ProvisionGreenInstanceJob`
5. Quota fail → `InstanceQuotaExceededException` → JSON 422 in `bootstrap/app.php`

## If you change this

- **Hits:** Pest InstanceProvisioningTest, contract POST /instances, Green spend on create
- **Does not hit:** DELETE path (still platform `ensurePlatformService`)

## Surfaces

| Surface | Role |
|---------|------|
| Client Bearer | create within cupo |
| Platform + X-Tenant-Id | create within same cupo |
| Horizon | runs provision job |

## See

- Objects: `objects/api/instancia.md`, `objects/api/tenant-max-instances.md`
- Source: `app/Services/GreenApi/InstanceProvisioningService.php`
- Session: `objects/sessions/2026-09-01-client-create-instance-quota.md`
