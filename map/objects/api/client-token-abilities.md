---
type: object
cluster: api
universe: live
status: verified
as_of: 2026-09-01
commit: f35560b
entity: config/permissions.php
---

# Client token abilities

Tenant API access = Sanctum token **plus** Spatie permissions on the api-client user. Route middleware checks **user** permissions (`permission:instancias.crear`), not the Sanctum ability list alone.

## Why this shape

Ability lists on the token are for documentation/issue defaults; enforcement is Spatie. After deploy, `php artisan tenants:sync-client-permissions` grants new abilities to existing users without reissuing every token.

## Shape — `demo_client_abilities` (live)

- `instancias.ver`
- `instancias.crear` ← added PR #51
- `mensajes.enviar`
- `mensajes.ver`
- `cuenta.ver`

**Not included:** `instancias.eliminar` (delete remains platform).

Allowlist for explicit issue: `StoreTenantTokenRequest`.

Citations: `config/permissions.php`; `routes/api.php` middleware; contract abilities table

## Connected to

- **joins:** [[endpoints-v1]], activate-plan token rotate
- **looks-like-but-is-not:** platform_service permission set (includes eliminar)

## If you change this

- **Hits:** issue token, activate-plan, sync command, client create path
- **Does not hit:** Green Partner credentials

## Surfaces

| Surface | Role |
|---------|------|
| activate-plan / issueToken | writes abilities + syncs Spatie |
| Client apps | call API with Bearer |

## See

- Source: `config/permissions.php`
- Command: `tenants:sync-client-permissions`
