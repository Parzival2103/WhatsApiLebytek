---
type: object
cluster: api
universe: live
status: verified
as_of: 2026-09-01
commit: f35560b
entity: app/Models/Integration/Instancia.php
---

# Instancia (WhatsApp instance)

Row in `int_instancias`. Product “WhatsApp instance”; Green id lives in `id_instance` when provisioned.

## Why this shape

One tenant can have multiple instances (cupo via `Tenant.max_instances`). SoftDeletes: deleted rows do **not** count toward cupo; `failed` **does** until delete or same-`external_ref` retry.

## Shape

- ULID `public_id` route key
- `tenant_id`, `label`, `external_ref`, `purpose` (`demo`|`production`)
- `status` lifecycle: `provisioning` → … → `authorized` / `failed` / `deleting`
- SoftDeletes trait

Citations: `app/Models/Integration/Instancia.php` (SoftDeletes); count in `InstanceProvisioningService::ensureInstanceQuotaAvailable`

## Connected to

- **owned-by:** Tenant
- **joins:** ProvisionGreenInstanceJob, DELETE platform-only
- **looks-like-but-is-not:** tenant `public_id` (never interchange)

## If you change this

- **Hits:** create/list/show/qr/delete API, quota count, Green jobs
- **Does not hit:** message monthly quota (`messages_monthly_limit`)

## Surfaces

| Surface | Role |
|---------|------|
| Client Bearer | create (cupo), list, show, qr — **not** delete |
| Platform Bearer | create, delete, ops |

## See

- Source: `app/Models/Integration/Instancia.php`
- Process: `processes/create-instance.md`
