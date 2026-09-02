# map/ — WhatsApiLebytek system map (ICM)

Walkable edit map for agents: nouns (API domain), verbs (HTTP/async flows), and change-impact. **Source of truth stays in code + `docs/integration/waapi-api-contract.md`.** This map cites; it does not replace the contract.

Built on ICM (Interpretable Context Methodology). Load only the catalog + the card you need.

## Universes

| Tag | Meaning |
|-----|---------|
| **live** | In force on `main` / VPS |
| **leftover** | Present but not the main path |
| **ghost** | Planned or named, not wired yet — do not implement against as done |

## Name collisions

| Product / chat | Code |
|----------------|------|
| Tenant | `App\Models\Core\Tenant` · table `core_tenants` |
| Instance / WhatsApp | `App\Models\Integration\Instancia` · table `int_instancias` |
| Client Bearer | Sanctum token on tenant `api-client+…` user + Spatie perms |
| Platform Bearer | Platform admin user (`tenant_id` null) + `X-Tenant-Id` |
| Cupo instancias | Column `core_tenants.max_instances` + `PlanCatalog::resolveMaxInstances` |
| Portal / waapi | **Other repo** `Lebytek_Portal` — not this tree |

## Where things live

| Folder | What it holds |
|--------|----------------|
| `objects/` | Nouns — endpoints, tenant cupo, instancia, tokens, session ship notes |
| `processes/` | Verbs — create instance, account status, activate-plan cupo |
| `effects/` | “If you change X, open these cards” |
| `_templates/` | Blank object/process cards |
| `_meta/schema.md` | Closed node types |

## Route by task

| If you need… | Open |
|--------------|------|
| Endpoint list (auth + cupo notes) | `objects/api/endpoints-v1.md` |
| Client create instance + 422 quota | `processes/create-instance.md` + `objects/sessions/2026-09-01-client-create-instance-quota.md` |
| `account/status` `instances` block | `processes/account-status.md` |
| Plan cupo persistence | `objects/api/tenant-max-instances.md` |
| What a change hits | `effects/CONTEXT.md` |
| Full HTTP contract | `../docs/integration/waapi-api-contract.md` |
| Deploy VPS | `../docs/DEPLOY.md` |

## The one rule

Do not invent API behavior from this map alone. Verify against contract + code citations on the card.
