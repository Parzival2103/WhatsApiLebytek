---
type: process
status: verified
as_of: 2026-09-01
commit: f35560b
consumes: [tenant-max-instances, client-token-abilities]
produces: [tenant-max-instances, client-token-abilities]
---

# activate-plan-cupo

Platform unlocks a paid plan: sets commercial fields including `max_instances`, rotates client tokens with current `demo_client_abilities`.

## Input → Movement → Output

Platform + tenant publicId + planSlug (+ optional empresa `maxInstances`) → `ActivatePlanService` → tenant row + new plain token once.

## Why this shape

Cupo must stick on the tenant after activation so later creates do not re-read only slug from memory.

## Steps

1. `ActivatePlanRequest` validates optional `maxInstances` (`prohibited_unless:planSlug,empresa`)
2. `PlanCatalog::resolveMaxInstances` then `forceFill` `max_instances`
3. Revoke + issue token with `config('permissions.demo_client_abilities')` (includes `instancias.crear`)

## If you change this

- **Hits:** ActivatePlanTest, Portal authorize-payment, cupo for all future creates
- **Does not hit:** Green instance reuse (activate does not re-provision)

## Surfaces

| Surface | Role |
|---------|------|
| Platform / Framework | calls activate-plan |

## See

- Source: `app/Services/ActivatePlanService.php`
- Object: `objects/api/tenant-max-instances.md`
