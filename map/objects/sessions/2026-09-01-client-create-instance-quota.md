---
type: session
cluster: sessions
universe: live
status: verified
as_of: 2026-09-01
commit: f35560b
pr: 51
---

# Session ship — client create instance + plan quota

What landed on `main` / VPS `api.lebytek.com` (2026-09-01).

## Delivered

1. Catalog `max_instances` + `PlanCatalog::resolveMaxInstances`
2. Column `core_tenants.max_instances` (migrate + backfill); demo + activate-plan writers; empresa optional `maxInstances`
3. Quota guard → **422** Spanish upgrade message (no platform bypass)
4. `POST /instances` opened to tenant Bearer with `instancias.crear` (`resolveTenantAccess`)
5. `account/status` → `instances.used` / `instances.limit`
6. Contract + sync-permissions notes
7. Prod smoke: business 1→3 creates 202; 4th 422; idempotent externalRef 200

## Explicitly not done

| Item | Status |
|------|--------|
| Client `DELETE /instances` | **ghost** — still platform-only; needed later so clients can free cupo |
| docsV2 sandbox POST instances + UI | follow-up other repo |
| Portal used/limit widgets | follow-up; API is SoT |
| lockForUpdate on quota count | optional TOCTOU harden |

## Docs / plan

- Spec: `docs/superpowers/specs/2026-08-31-client-create-instance-quota-design.md`
- Plan: `docs/superpowers/plans/2026-08-31-client-create-instance-quota.md`
- PR: https://github.com/Parzival2103/WhatsApiLebytek/pull/51

## See

- Processes: `processes/create-instance.md`, `processes/account-status.md`
- Objects: [[api/tenant-max-instances]], [[api/client-token-abilities]]
