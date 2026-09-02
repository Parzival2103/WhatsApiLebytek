---
type: process
status: verified
as_of: 2026-09-01
commit: f35560b
consumes: [account-status, tenant-max-instances, instancia]
produces: []
---

# account-status

Client reads commercial + usage + instance cupo snapshot.

## Input → Movement → Output

Client Bearer (`cuenta.ver`) → `AccountStatusService::buildStatus` → JSON including `instances.used` / `instances.limit`.

## Why this shape

UI/sandbox need cupo without inventing a second source of truth.

## Steps

1. Route `POST /account/status` — `routes/api.php:94-97`
2. Controller rejects platform / missing tenant
3. Count instancias (same query shape as quota guard); limit from `tenant.max_instances`

## If you change this

- **Hits:** AccountStatusTest, Portal/docs consumers of payload
- **Does not hit:** create-instance guard logic (keep counts aligned manually if you fork the query)

## Surfaces

| Surface | Role |
|---------|------|
| Client | reads |
| docsV2 | follow-up allowlist |

## See

- Object: `objects/api/account-status.md`
- Source: `app/Services/AccountStatusService.php`
