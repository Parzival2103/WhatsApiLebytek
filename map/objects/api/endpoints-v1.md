---
type: object
cluster: api
universe: live
status: verified
as_of: 2026-09-01
commit: f35560b
entity: routes/api.php
---

# Endpoints `/api/v1`

HTTP surface of WhatsApiLebytek. Product contract: `docs/integration/waapi-api-contract.md`. Route wiring: `routes/api.php`.

## Why this shape

Single versioned API for Portal/docs sandbox/ops. Auth is Sanctum + Spatie `permission:*` (user perms). Idempotency-Key on mutating routes in the main group.

## Shape — route table (live)

Prefix `https://api.lebytek.com/api/v1` unless noted.

| Method | Path | Permission | Who |
|--------|------|------------|-----|
| GET | `/health` | `api.health` | platform / health |
| GET | `/tenants` | `tenants.ver` | platform |
| POST | `/tenants` | `tenants.provisionar` | platform |
| GET | `/tenants/{publicId}` | `tenants.ver` | platform |
| PATCH | `/tenants/{publicId}` | `tenants.gestionar` | platform |
| POST | `/tenants/{publicId}/tokens` | `tenants.gestionar` | platform |
| POST | `/tenants/{publicId}/activate-plan` | `tenants.gestionar` | platform |
| POST | `/tenants/{publicId}/cancel-commercial` | `tenants.gestionar` | platform |
| POST | `/tenants/{publicId}/reactivate-commercial` | `tenants.gestionar` | platform |
| POST | `/admin/demo-leads-snapshot` | (admin snapshot) | platform |
| GET | `/instances` | `instancias.ver` | **client or** platform+`X-Tenant-Id` |
| POST | `/instances` | `instancias.crear` | **client or** platform+`X-Tenant-Id` (+ **cupo**) |
| GET | `/instances/{publicId}` | `instancias.ver` | client or platform |
| GET | `/instances/{publicId}/qr` | `instancias.ver` | client or platform |
| DELETE | `/instances/{publicId}` | `instancias.eliminar` | **platform only** |
| POST | `/messages` | `mensajes.enviar` | client or platform |
| GET | `/messages/{publicId}` | `mensajes.ver` | client or platform |
| GET | `/usage` | (usage) | client or platform |
| POST | `/account/status` | `cuenta.ver` | **client only** (tenant-bound) |
| POST | `/webhooks/incoming` | webhook signature | Green → API (no Sanctum) |

Citations: `routes/api.php:13-101`

## Connected to

- **owns:** all controllers under `app/Http/Controllers/Api/V1/`
- **joins:** [[client-token-abilities]], [[tenant-max-instances]], [[instancia]]
- **looks-like-but-is-not:** Portal routes on `waapi.lebytek.com` (other repo)

## If you change this

- **Hits:** contract doc, Scribe/OpenAPI export, Portal/docsV2 clients, Pest feature tests
- **Does not hit:** Inertia admin UI pages (separate surface)

## Surfaces

| Surface | Role |
|---------|------|
| Portal / Framework | writes via platform + client tokens |
| docsV2 sandbox | subset GET (+ future POST instances) |
| Agents / ops | `ssh lebytek-vps`, curl smoke |

## See

- Source: `routes/api.php`
- Contract: `docs/integration/waapi-api-contract.md`
