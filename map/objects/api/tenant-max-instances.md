---
type: object
cluster: api
universe: live
status: verified
as_of: 2026-09-01
commit: f35560b
entity: app/Models/Core/Tenant.php
---

# Tenant `max_instances` (cupo)

Commercial cap on how many WhatsApp instances a tenant may own. Parallel to `messages_monthly_limit`.

## Why this shape

Portal sells 1 vs 3 instances by plan; API is SoT for enforcement. Persisted column so activate-plan / empresa override do not depend only on in-memory slug.

## Shape

| planSlug | catalog `max_instances` |
|----------|-------------------------|
| demo | 1 |
| starter | 1 |
| business | 3 |
| empresa | `null` (unlimited) unless `maxInstances` on activate-plan |

- Column: `core_tenants.max_instances` (nullable unsigned int)
- Resolve: `PlanCatalog::resolveMaxInstances($slug, $override)`
- Writers: demo provision, `ActivatePlanService`
- Guard: before **new** create in `InstanceProvisioningService::provision` → `InstanceQuotaExceededException` → HTTP **422**

Exact 422 message: `Has alcanzado el límite de instancias WhatsApp de tu plan. Mejora tu cuenta para generar otra instancia.`

Citations: `config/plans.php`; `app/Support/PlanCatalog.php`; migration `2026_08_31_000001_add_max_instances_to_core_tenants_table.php`

## Connected to

- **joins:** [[instancia]], [[endpoints-v1]] POST /instances, activate-plan
- **looks-like-but-is-not:** Portal `dom_mkt_paquetes.features` (marketing copy — not enforced there)

## If you change this

- **Hits:** create instance, account/status `instances.limit`, activate-plan, catalog tests
- **Does not hit:** message send rate limits (separate PlanCatalog fields)

## Surfaces

| Surface | Role |
|---------|------|
| Client | sees limit via account/status; blocked by 422 on create |
| Platform | same cupo (no bypass) |
| Ops | `tenants:sync-client-permissions` after ability changes |

## See

- Source: `app/Models/Core/Tenant.php` fillable `max_instances`
- Spec: `docs/superpowers/specs/2026-08-31-client-create-instance-quota-design.md`
