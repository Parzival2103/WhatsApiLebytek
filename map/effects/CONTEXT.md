---
type: effect-index
status: verified
as_of: 2026-09-01
---

# effects — open these before changing X

| If you are changing… | Open first |
|----------------------|------------|
| POST /instances auth or cupo | `processes/create-instance.md`, `objects/api/tenant-max-instances.md`, `objects/api/client-token-abilities.md` |
| 422 upgrade copy | `InstanceQuotaExceededException`, contract POST /instances, Portal email plans |
| account/status shape | `processes/account-status.md`, `objects/api/account-status.md` |
| Plan catalog limits | `objects/api/tenant-max-instances.md`, `config/plans.php`, ActivatePlanTest |
| Client abilities list | `objects/api/client-token-abilities.md`, sync command, contract table |
| DELETE /instances for clients | **ghost** — read session ship note; new feature: drop `ensurePlatformService` on destroy, add `instancias.eliminar` to demo abilities, free cupo on SoftDelete |
| Endpoint list / OpenAPI | `objects/api/endpoints-v1.md`, `docs/integration/waapi-api-contract.md` |
| Deploy / migrate cupo | `docs/DEPLOY.md`, migration `2026_08_31_000001_*`, `tenants:sync-client-permissions` |

If this index and a card disagree, fix the card.
