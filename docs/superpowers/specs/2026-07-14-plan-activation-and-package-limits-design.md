# Plan activation + package limits (paid membership)

**Date:** 2026-07-14  
**Repo:** WhatsApiLebytek (`api.lebytek.com`)  
**Status:** Approved for implementation — see plan `docs/superpowers/plans/2026-07-14-plan-activation-and-package-limits.md`  

**Companion spec (back-office):** `Lebytek_Framework/docs/superpowers/specs/2026-07-14-manual-membership-purchase-design.md`

## Problem

Demos provision with `commercial_status=demo` and `messages_monthly_limit=100`. Paid packages live primarily in Framework (`dom_mkt_paquetes`), but api has no atomic **activate paid plan** operation that:

1. Raises monthly quota from a server-side catalog (not client input).
2. Keeps the same Green instance (no re-QR).
3. Revokes demo Sanctum tokens and issues a fresh tenant token for the confirmation email.

HTTP send throttle is also flat (`messages-send` = 10/min) while job Redis throttle is 30/min; paid plans should be able to raise these per package later.

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

Align with Framework landing claims + VPS packages (backfill Framework `mensajes_mes_limite` in companion work):

| `planSlug` | `planName` | `messagesMonthlyLimit` | HTTP `messages-send` / min (target) | Job Redis / min (target) |
|------------|------------|------------------------|-------------------------------------|---------------------------|
| `demo` | Demo | 100 | 10 | 30 |
| `starter` | Starter | 5000 | 30 | 60 |
| `business` | Business | 80000 | 60 | 120 |
| `empresa` | Enterprise | `null` (custom; set by ops) | config default high / custom | config |

Store in `config/plans.php` (or `config/commercial.php`). **Never** accept arbitrary limits from tenant tokens. Platform activate-plan reads slug → map; Enterprise may pass explicit `messagesMonthlyLimit` only when slug=`empresa` and value is validated min/max.

## Security model

| Actor | Can activate plan? | Can set arbitrary high quota? |
|-------|--------------------|-------------------------------|
| Tenant Bearer | No | No |
| Platform Bearer | Yes (activate-plan) | Only via slug map; Enterprise override audited |
| Landing `?compras=1` | N/A (Framework UI only) | N/A |

Risk addressed: “jumping” demo → pro by client PATCH is blocked if update routes stay platform-only (already true for `TenantController::update`) **and** activate-plan does not accept client-chosen limits for standard slugs. Reissuing token invalidates leaked demo credentials after paid unlock.

## API — activate plan

**`POST /api/v1/tenants/{tenant:public_id}/activate-plan`**

- Auth: platform service only (`ensurePlatformService`).
- Permission: existing `tenants.gestionar` (or document new ability if required).
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
| `billingCycle` | `monthly` \| `annual` (stored in tenant `meta` or column if added) |
| `orderExternalRef` | required string; Framework order public id for audit |
| `messagesMonthlyLimit` | optional; **only** honored when `planSlug=empresa` |
| `tokenName` | optional; default `cliente-{slug}` |

**Effects (atomic transaction where practical):**

1. Load tenant; refuse if soft-deleted.
2. Resolve limits from catalog (+ Enterprise override).
3. Update tenant:
   - `commercial_status = active`
   - `plan_slug`, `plan_name`
   - `messages_monthly_limit` = resolved value
   - clear or keep `demo_expires_at` (prefer clear paying expiry; keep `demo_*` historical fields if useful — prefer `demo_expires_at = null` on paid)
   - `meta.billing_cycle`, `meta.activated_order_ref`, `meta.activated_at`
4. Revoke all personal access tokens for the tenant’s `api-client` user(s) (or all non-platform tokens bound to that tenant).
5. Issue new Sanctum token with production abilities (same set as demo client at minimum: `instancias.ver`, `mensajes.enviar`, `mensajes.ver`, `cuenta.ver`; document expansion later).
6. Return **201** once:

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

Plain token returned **once** (same pattern as `issueToken`).

**Errors:**

| Status | When |
|--------|------|
| 403 | Non-platform |
| 404 | Unknown tenant |
| 422 | Unknown slug / invalid Enterprise limit |
| 409 | Optional: already `active` on same slug+orderRef (idempotent 200 OK preferred) |

## Existing `PATCH /tenants/{id}`

Remains platform-only for ops patches. Prefer Framework authorize path use **activate-plan** so revoke+issue stays atomic. Document that using bare PATCH without revoke leaves old demo tokens valid — discouraged.

## Send-rate by plan (same delivery)

Raise HTTP limiter and job middleware from flat constants to plan-aware values:

1. Resolve tenant plan from message / job context.
2. `RateLimiter::for('messages-send')` → allow from `config/plans.php` for that tenant’s slug (fallback demo).
3. `TransactionalMessageJob` middleware → `maxAttempts` from plan job rate.

If tenant has no slug, use demo rates.

## Contract sync

Update `docs/integration/waapi-api-contract.md` (and Framework mirror when shipping) with activate-plan. Framework `LebytekApiClient` gains `activatePlan(tenantPublicId, payload)`.

## Testing (api)

- Platform can activate starter → limit 5000, status active, token works, old token 401.
- Tenant token cannot call activate-plan (403).
- Tenant token cannot PATCH commercial fields (already 403).
- Idempotent replay with same Idempotency-Key / orderExternalRef.
- Empresa with custom limit accepted; starter with custom limit ignored or 422.
- Rate limiter uses plan config (unit/feature with faked tenant plan).

## Cross-repo sequence

```
Framework Admin Autorizar
  → POST /tenants/{id}/activate-plan  (LEBYTEK_API_TOKEN)
  → email membership + token from response
```

Ship this endpoint before enabling Authorize in production Framework.

## Out of scope notes

- Cuota enforcement stays `AccountStatusService` + `MessageSendService` (queued|sent count).
- Campaigns / multi-instance hard caps per plan can follow in a later spec using the same catalog.
