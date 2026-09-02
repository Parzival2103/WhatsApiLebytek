---
type: object
cluster: api
universe: live
status: verified
as_of: 2026-09-01
commit: f35560b
entity: app/Services/AccountStatusService.php
---

# Account status payload

`POST /api/v1/account/status` — tenant-bound commercial snapshot for client Bearer (`cuenta.ver`). Platform tokens are rejected.

## Why this shape

Sandbox/Portal need cupo and usage without scraping list endpoints. `instances` block added with the quota feature.

## Shape (relevant keys)

```json
{
  "commercialStatus": "active|demo|…",
  "plan": { "slug", "name", "messagesPerMonthLimit" },
  "usage": { "messagesSentThisMonth", "messagesRemainingThisMonth", "messagesLimitThisMonth" },
  "instances": { "used": 1, "limit": 3 }
}
```

- `instances.used` — same count as quota guard (non soft-deleted)
- `instances.limit` — `tenant.max_instances` (`null` = unlimited)

Citations: `app/Services/AccountStatusService.php`; `AccountStatusController`

## Connected to

- **joins:** [[tenant-max-instances]], [[instancia]]
- **looks-like-but-is-not:** `GET /usage` (messages-focused)

## If you change this

- **Hits:** client dashboards, docsV2 sandbox (when allowlisted), Pest AccountStatusTest
- **Does not hit:** create-instance auth

## Surfaces

| Surface | Role |
|---------|------|
| Client Bearer | reads |
| Platform | forbidden |

## See

- Source: `app/Services/AccountStatusService.php`
- Process: `processes/account-status.md`
