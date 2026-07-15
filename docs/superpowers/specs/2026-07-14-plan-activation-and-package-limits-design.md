# Plan activation + package limits (paid membership)

**Date:** 2026-07-14  
**Updated:** 2026-07-14 (post-implementation audit)  
**Repo:** WhatsApiLebytek (`api.lebytek.com`)  
**Status:** Implemented on `main` (PR #14) — **ops migrate/smoke pending**  
**Plan (code tasks):** `docs/superpowers/plans/2026-07-14-plan-activation-and-package-limits.md`  
> Note: plan markdown checkboxes were left unchecked after ship; treat **code + PR #14** as source of truth, not the `- [ ]` markers.

**Companion spec (back-office):** `Lebytek_Framework/docs/superpowers/specs/2026-07-14-manual-membership-purchase-design.md`  
**Shipped commits:** `d4f22b5` (docs), `f20c02e` / `7057035` / `f202e32` (feat), merge `30bc4b6`

## Implementation status (audit 2026-07-14)

### Done (code on `main`)

| Spec item | Where |
|-----------|--------|
| Canonical catalog | `config/plans.php` + `App\Support\PlanCatalog` |
| `POST /api/v1/tenants/{tenant}/activate-plan` | route + `TenantController::activatePlan` + `ActivatePlanRequest` |
| Platform-only + `tenants.gestionar` + Idempotency-Key | middleware group + `ensurePlatformService` |
| Atomic activate + token rotate | `ActivatePlanService` + `TenantTokenService::revokeClientTokens` |
| Effects: `commercial_status=active`, plan fields, clear `demo_expires_at`, `meta.*` | service + migration `2026_07_14_160000_add_meta_to_core_tenants_table` |
| Semantic idempotency (same slug + orderExternalRef → 200, `token: null`) | service (+ unit tests) |
| Plan-aware HTTP `messages-send` + job Redis maxAttempts | `PlanRateResolver`, `AppServiceProvider`, `TransactionalMessageJob` |
| Contract docs | `docs/integration/waapi-api-contract.md` (marked **Implementado**) |
| Tests | Feature `ActivatePlanTest`, `PlanAwareMessageSendThrottleTest`; Unit PlanCatalog / PlanRateResolver / ActivatePlanService / revoke |

### Partial / optional gaps

| Gap | Severity | Notes |
|-----|----------|--------|
| Feature test for semantic 200 replay | Done | `ActivatePlanTest` — different Idempotency-Key |
| Direct assert of job middleware `maxAttempts` | Done | `HorizonQueueConfigTest` + Reflection |
| Plan file checkboxes | Info | Historical `- [ ]` markers — header notes shipped status |
| Soft-deleted tenant | Info | SoftDeletes route binding → 404; no explicit service guard |

### Not done (ops / next)

1. **VPS:** ensure `meta` migration applied on `api.lebytek.com` before Framework Authorize calls activate-plan.
2. **Smoke:** platform token → activate starter on a demo tenant → old token 401, new token works, quota raised, same Green instance.
3. **Companion Framework:** membership purchase flow is coded; enable `MKT_MEMBERSHIP_AUTHORIZE_ENABLED` only after smoke above.
4. ~~Optional niceties: Feature test semantic 200; job middleware rate assert; mark plan checkboxes Done.~~ **Done (2026-07-14)** — closure plan Tasks 1–3.
5. Out of scope still: campaigns / multi-instance hard caps; billing portal.

Plan checklist reality: **Tasks 1–7 shipped**; Task 8 = regression run / ops handoff (process).

---

## Problem

Demos provision with `commercial_status=demo` and `messages_monthly_limit=100`. Paid packages live primarily in Framework (`dom_mkt_paquetes`), but api needs an atomic **activate paid plan** operation that:

1. Raises monthly quota from a server-side catalog (not client input).
2. Keeps the same Green instance (no re-QR).
3. Revokes demo Sanctum tokens and issues a fresh tenant token for the confirmation email.

HTTP send throttle was flat (`messages-send` = 10/min) while job Redis throttle is 30/min; paid plans should raise these per package.

## Goal

- Platform-only **activate-plan** use case for Hybrid option A (same tenant + instance, reissue token).
- Canonical plan catalog in api (slug → limits + optional send rates).
- Tenant token **cannot** escalate `commercialStatus` / quotas / plan.
- Document contract for Framework authorize-payment orchestration.

## Non-goals (v1)

- Payment capture (Framework owns transfer UX).
- New Green instance / reprovision.
- Billing portal / invoices.
- Automatic renewals / dunning.

## Canonical plan map (v1)

Align with Framework landing claims + VPS packages:

| `planSlug` | `planName` | `messagesMonthlyLimit` | HTTP `messages-send` / min | Job Redis / min |
|------------|------------|------------------------|----------------------------|-----------------|
| `demo` | Demo | 100 | 10 | 30 |
| `starter` | Starter | 5000 | 30 | 60 |
| `business` | Business | 80000 | 60 | 120 |
| `empresa` | Enterprise | `null` (custom; set by ops) | config default high / custom | config |

Stored in `config/plans.php`. **Never** accept arbitrary limits from tenant tokens. Platform activate-plan reads slug → map; Enterprise may pass explicit `messagesMonthlyLimit` only when slug=`empresa` and value is validated min/max.

## Security model

| Actor | Can activate plan? | Can set arbitrary high quota? |
|-------|--------------------|-------------------------------|
| Tenant Bearer | No | No |
| Platform Bearer | Yes (activate-plan) | Only via slug map; Enterprise override audited |
| Landing `?compras=1` | N/A (Framework UI only) | N/A |

Risk addressed: “jumping” demo → pro by client PATCH is blocked if update routes stay platform-only (already true for `TenantController::update`) **and** activate-plan does not accept client-chosen limits for standard slugs. Reissuing token invalidates leaked demo credentials after paid unlock.

## API — activate plan

**`POST /api/v1/tenants/{tenant:public_id}/activate-plan`** — **shipped**

- Auth: platform service only (`ensurePlatformService`).
- Permission: `tenants.gestionar`.
- Idempotency-Key: required (same as other mutating v1 routes).

**Body:**

```json
{
  "planSlug": "starter",
  "billingCycle": "monthly",
  "orderExternalRef": "01JXORDER…",
  "messagesMonthlyLimit": null,
  "tokenName": "cliente-paid-starter"
}
```

| Field | Rules |
|-------|--------|
| `planSlug` | required; must exist in catalog |
| `billingCycle` | `monthly` \| `annual` (stored in tenant `meta`) |
| `orderExternalRef` | required string; Framework order public id for audit |
| `messagesMonthlyLimit` | optional; **only** honored when `planSlug=empresa` |
| `tokenName` | optional; default `cliente-{slug}` |

**Effects (atomic transaction where practical):**

1. Load tenant; refuse if soft-deleted (route SoftDeletes → 404).
2. Resolve limits from catalog (+ Enterprise override).
3. Update tenant:
   - `commercial_status = active`
   - `plan_slug`, `plan_name`
   - `messages_monthly_limit` = resolved value
   - `demo_expires_at = null`
   - `meta.billing_cycle`, `meta.activated_order_ref`, `meta.activated_at`
4. Revoke all personal access tokens for the tenant’s `api-client` user(s).
5. Issue new Sanctum token with `config('permissions.demo_client_abilities')`.
6. Return **201** once (plain token); semantic replay same slug+orderRef → **200** with `token: null`.

```json
{
  "tenant": { "...TenantResource..." },
  "token": "17|…",
  "plan": {
    "slug": "starter",
    "name": "Starter",
    "messagesMonthlyLimit": 5000,
    "billingCycle": "monthly"
  }
}
```

**Errors:**

| Status | When |
|--------|------|
| 403 | Non-platform |
| 404 | Unknown / soft-deleted tenant |
| 422 | Unknown slug / invalid Enterprise limit / starter override |
| 200 | Semantic idempotent replay (same slug + orderExternalRef) |
| 201 | First successful activation |

## Existing `PATCH /tenants/{id}`

Remains platform-only for ops patches. Prefer Framework authorize path use **activate-plan** so revoke+issue stays atomic. Using bare PATCH without revoke leaves old demo tokens valid — discouraged (documented in contract).

## Send-rate by plan (same delivery) — **shipped**

1. Resolve tenant plan from message / job context via `PlanRateResolver`.
2. `RateLimiter::for('messages-send')` → allow from `config/plans.php` (fallback demo).
3. `TransactionalMessageJob` middleware → `maxAttempts` from plan job rate.

If tenant has no slug, use demo rates.

## Contract sync

| Doc | Status |
|-----|--------|
| WhatsApiLebytek `docs/integration/waapi-api-contract.md` | Updated — **Implementado** (canonical) |
| Framework `LebytekApiClient::activatePlan` | Shipped in companion PR |
| Framework `docs/integration/` mirror | Pending companion hardening plan Task 5 (copy **Implementado** section; do not invent Planificado) |

**Idempotency note for Framework:** `LebytekApiClient` issues a new `Idempotency-Key` per write. Admin retry after a successful activate hits **semantic** HTTP 200 + `token: null` (same `planSlug` + `orderExternalRef`), not the middleware cache. Companion authorize must treat null token as paid-without-email #3. Framework never sends `planSlug=demo` even if this endpoint’s FormRequest still accepts catalog keys including `demo`.

## Testing (api)

| Intent | Coverage |
|--------|----------|
| Platform activate starter → limit/status/token; old token 401 | Feature |
| Tenant token cannot activate-plan | Feature |
| Tenant cannot PATCH commercial fields | Existing |
| Idempotency-Key replay | Feature |
| Semantic orderRef idempotency | Unit (`ActivatePlanServiceTest`) |
| Empresa custom limit; starter override rejected | Feature + unit |
| HTTP rate limiter plan-aware | Feature `PlanAwareMessageSendThrottleTest` |
| Job Redis plan-aware | `PlanRateResolver` unit + job code |

## Cross-repo sequence

```
1. Api activate-plan on main                         ✅
2. Api VPS meta migrate + smoke (closure plan)       human / next
3. Framework hardening Tasks 1–7 (Task 2 blocker)    companion plan
4. Framework VPS migrations + MKT_BANK_*             companion Task 8
5. Enable MKT_MEMBERSHIP_AUTHORIZE_ENABLED           companion Task 8 after sequence steps 2–4

Framework Admin Autorizar
  → POST /tenants/{id}/activate-plan  (LEBYTEK_API_TOKEN)
  → email membership + token only when response.token is non-empty
  → semantic 200 + token null → mark paid, no email #3
  → never send planSlug=demo (authorize pre-flight)
```

| Layer | Status |
|-------|--------|
| Api endpoint | Done on `main` |
| Api residual tests / ops | `docs/superpowers/plans/2026-07-14-plan-activation-closure.md` |
| Framework client + authorize UI | Done on `feature/backoffice-api-integration` |
| Framework hardening | `Lebytek_Framework/docs/superpowers/plans/2026-07-14-manual-membership-purchase.md` |
| Prod smoke + enable authorize flag | **Next** (api smoke + Framework Task 2 before flag) |

## Out of scope notes

- Cuota enforcement stays `AccountStatusService` + `MessageSendService` (queued\|sent count).
- Campaigns / multi-instance hard caps per plan can follow in a later spec using the same catalog.
