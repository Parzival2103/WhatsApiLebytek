# Integration Docs Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align all `docs/integration/` (and cross-references) to the current architecture: **lebytek.com back-office** orchestrates api, **`dom_mkt_leads` + `lebytek_lead_{id}`**, 2º correo with tenant token (waapi optional), without changing application code.

**Architecture:** Enfoque B from spec — rewrite + rename with legacy stubs. `WhatsApiLebytek` is source of truth; mirror to `Lebytek_Framework/docs/integration/` in final task. Legacy filenames (`role-delegation-waapi.md`, `waapi-implementation-real.md`) become short redirect stubs only.

**Tech Stack:** Markdown, Mermaid, ripgrep verification, git (WhatsApiLebytek + Lebytek_Framework).

**Spec:** `docs/superpowers/specs/2026-06-30-integration-docs-alignment-design.md`

## Global Constraints

- Consumidor provisioning: **Back-office lebytek.com** (not waapi as orchestrator).
- Repo back-office: `Parzival2103/Lebytek_Framework`, branch `feature/backoffice-api-integration`.
- `externalRef`: **`lebytek_lead_{leadId}`** (legacy alias: `waapi_org_{id}` — mention only).
- Local columns: **`dom_mkt_leads`**: `api_tenant_public_id`, `external_ref`, `api_provisioned_at`, `api_provision_error`.
- Env back-office: `LEBYTEK_API_URL=https://api.lebytek.com/api/v1`, `LEBYTEK_API_TOKEN`.
- Env api service user (code today): **`WAAPI_SERVICE_EMAIL`** / `WAAPI_SERVICE_NAME` (alias `PLATFORM_SERVICE_*` documented as P2).
- Token CLI: `php artisan integration:issue-waapi-token [--revoke]` (command name unchanged).
- 2º correo v1: **token Sanctum por-tenant mandatory**; waapi URL **optional / later**.
- `POST /tenants/{publicId}/tokens`: **contract yes, code not yet** — must be labeled pending in contract.
- **No** application code changes in this plan (docs only).
- waapi.lebytek.com: **frozen**; docs.lebytek.com: **untouched**.

---

## File map (before coding)

| File | Action | Responsibility |
|------|--------|----------------|
| `docs/ARCHITECTURE.md` | Modify | Domain map, repos, integration links |
| `CLAUDE.md` | Modify | Integration doc links |
| `README.md` | Modify | One-line provisioning consumer fix |
| `docs/integration/waapi-api-contract.md` | Modify | HTTP contract vocabulary + 2º correo + pending endpoint |
| `docs/integration/role-delegation-lebytek-api.md` | **Create** | Role split api ↔ lebytek.com |
| `docs/integration/role-delegation-waapi.md` | Replace with stub | Redirect to new file |
| `docs/integration/lebytek-implementation-real.md` | **Create** | Framework PHP implementation guide |
| `docs/integration/waapi-implementation-real.md` | Replace with stub | Redirect to new file |
| `docs/integration/VPS_CHECKLIST.md` | Modify | lebytek.com VPS section, waapi frozen |
| `docs/integration/README.md` | **Create** | Index of integration docs |
| `docs/integration/prompt2-review-pre-waapi.md` | Modify | Historical banner only |
| `Lebytek_Framework/docs/integration/*` | Mirror | Copy from api repo after Task 6 |

---

### Task 1: Architecture and top-level cross-links

**Files:**
- Modify: `docs/ARCHITECTURE.md`
- Modify: `CLAUDE.md`
- Modify: `README.md` (integration mention ~line 78)

**Interfaces:**
- Consumes: spec §5 domain map, §4 vocabulary
- Produces: canonical links to `role-delegation-lebytek-api.md`, `lebytek-implementation-real.md`

- [ ] **Step 1: Verify stale content exists (baseline)**

Run from repo root `WhatsApiLebytek`:

```bash
rg "Ninguna con api|Provisiona tenants|role-delegation-waapi|waapi-implementation-real" docs/ARCHITECTURE.md CLAUDE.md README.md
```

Expected: matches in `ARCHITECTURE.md` (e.g. "Ninguna con api", waapi orchestration).

- [ ] **Step 2: Rewrite `docs/ARCHITECTURE.md`**

Replace the ASCII diagram and domain table with spec §5 content. Minimum required sections:

```markdown
## Ecosistema (2026-06-30)

| Dominio | Rol hoy | Repo | Integración api |
|---------|---------|------|-----------------|
| **lebytek.com** | Back-office + landing; FTP México = legacy pre-1.0 | Lebytek_Framework (`feature/backoffice-api-integration`) | **Orquesta** (Bearer plataforma) |
| **api.lebytek.com** | Motor WhatsApp + admin ops | WhatsApiLebytek (`main`) | Fuente de verdad técnica |
| **waapi.lebytek.com** | Panel cliente (fase final) | congelado en VPS | Solo lectura futura; no orquestador |
| **docs.lebytek.com** | Docs públicas | placeholder | Sin cambios |

**Deploy:** lebytek.com target VPS `/home/lebytek/htdocs/lebytek.com` (docroot `public/`). DNS cutover after E2E.

## Contrato de integración

- [waapi-api-contract.md](integration/waapi-api-contract.md) — HTTP v1
- [role-delegation-lebytek-api.md](integration/role-delegation-lebytek-api.md) — responsabilidades
- [lebytek-implementation-real.md](integration/lebytek-implementation-real.md) — guía Framework
- [prompt2-review-pre-waapi.md](integration/prompt2-review-pre-waapi.md) — auditoría histórica api
- [VPS_CHECKLIST.md](integration/VPS_CHECKLIST.md)
```

Remove sentences: "lebytek.com no se integra con api", "waapi provisiona tenants".

- [ ] **Step 3: Update `CLAUDE.md` integration links**

Replace the three bullets under integration with:

```markdown
- Contrato API: `docs/integration/waapi-api-contract.md`
- Delegación roles (lebytek.com ↔ api): `docs/integration/role-delegation-lebytek-api.md`
- Guía implementación back-office: `docs/integration/lebytek-implementation-real.md`
- Spec alineación docs: `docs/superpowers/specs/2026-06-30-integration-docs-alignment-design.md`
```

- [ ] **Step 4: Fix `README.md` provisioning line**

Change consumer from "waapi" to "back-office lebytek.com"; link unchanged.

- [ ] **Step 5: Verify**

```bash
rg "Ninguna con api|waapi provisiona|role-delegation-waapi\.md|waapi-implementation-real\.md" docs/ARCHITECTURE.md CLAUDE.md README.md
```

Expected: **no matches** (except intentional stub references if any).

- [ ] **Step 6: Commit**

```bash
git add docs/ARCHITECTURE.md CLAUDE.md README.md
git commit -m "docs: align architecture and cross-links to lebytek.com consumer"
```

---

### Task 2: HTTP contract (`waapi-api-contract.md`)

**Files:**
- Modify: `docs/integration/waapi-api-contract.md`

**Interfaces:**
- Consumes: Task 1 vocabulary
- Produces: updated contract with `lebytek_lead_*`, 2º correo section, pending `POST /tokens`

- [ ] **Step 1: Baseline grep**

```bash
rg "waapi_org_|organizations\.api_tenant|Copiar token → waapi|PLATFORM_SERVICE_EMAIL" docs/integration/waapi-api-contract.md
```

Expected: multiple matches (pre-change).

- [ ] **Step 2: Global replace examples**

| Find | Replace |
|------|---------|
| `waapi_org_42` | `lebytek_lead_42` |
| `waapi_org_test` | `lebytek_lead_test` |
| "waapi debe persistir \`publicId\` en su tabla \`organizations.api_tenant_public_id\`" | "El back-office persiste \`publicId\` en \`dom_mkt_leads.api_tenant_public_id\`" |

- [ ] **Step 3: Add implementation status to `POST /tenants/{publicId}/tokens`**

Immediately under that endpoint heading, insert:

```markdown
> **Estado implementación (api):** contrato acordado; **pendiente en código** — no existe en `routes/api.php` al 2026-06-30. Requerido antes del flujo 2º correo.
```

- [ ] **Step 4: Add section "Entrega al cliente (2º correo)"** before "Bootstrap en producción"

```markdown
## Entrega al cliente (2º correo)

Tras aprobar lead y provisioning en api, el back-office de **lebytek.com** envía un segundo correo al cliente.

| Elemento | v1 operativa | Notas |
|----------|--------------|-------|
| Token Sanctum por-tenant | **Obligatorio** | Emitido vía `POST /tenants/{publicId}/tokens` (pendiente implementación api) |
| URL / login waapi | Opcional | Fase posterior; panel congelado |
| Instrucciones API (`api.lebytek.com`) | Recomendado | Base URL + uso del token |
| Token Green API crudo | **Prohibido** | Nunca en correo ni respuestas api |

Pago manual (transferencia) lo gestiona lebytek.com antes del 2º correo.
```

- [ ] **Step 5: Fix bootstrap env block**

Replace `PLATFORM_SERVICE_EMAIL` block with:

```markdown
Variables api (código actual — ver `config/nucleo.php`):

```env
WAAPI_SERVICE_EMAIL=waapi-service@lebytek.internal
WAAPI_SERVICE_NAME="Lebytek Platform Service"
```

> Alias futuro documentado: `PLATFORM_SERVICE_*` (renombrado P2, no bloqueante).

Variables back-office **lebytek.com** (primario):

```env
LEBYTEK_API_URL=https://api.lebytek.com/api/v1
LEBYTEK_API_TOKEN=<token del comando artisan>
```

> waapi.lebytek.com mantiene copia legacy del token para fase panel; no es orquestador.
```

- [ ] **Step 6: Update references footer**

Change `role-delegation-waapi.md` → `role-delegation-lebytek-api.md`.

- [ ] **Step 7: Verify**

```bash
rg "waapi_org_|organizations\.api_tenant|Copiar token → waapi\.env" docs/integration/waapi-api-contract.md
rg "Entrega al cliente|pendiente en código|WAAPI_SERVICE_EMAIL" docs/integration/waapi-api-contract.md
```

Expected: first **no matches**; second **has matches**.

- [ ] **Step 8: Commit**

```bash
git add docs/integration/waapi-api-contract.md
git commit -m "docs: align API contract with lebytek.com consumer and 2nd email flow"
```

---

### Task 3: Role delegation (new file + stub)

**Files:**
- Create: `docs/integration/role-delegation-lebytek-api.md`
- Modify: `docs/integration/role-delegation-waapi.md` (replace body with stub)

**Interfaces:**
- Consumes: spec §6.2, Task 2 contract
- Produces: `role-delegation-lebytek-api.md` referenced by ARCHITECTURE and contract

- [ ] **Step 1: Create `docs/integration/role-delegation-lebytek-api.md`**

Write complete file (~120–180 lines) with these **mandatory sections**:

1. Title: `# Delegación de roles — lebytek.com ↔ api`
2. Mapa de dominios (table from spec §5 deploy)
3. Tabla responsabilidades (spec §6.2 table verbatim)
4. Regla de oro blockquote
5. Esquema SQL `dom_mkt_leads` (spec §6.2 SQL verbatim)
6. Convención `external_ref = lebytek_lead_{id}`
7. Variables `.env` lebytek.com (`LEBYTEK_API_*`)
8. Flujo onboarding numbered (lead form → admin approve → POST /tenants → Fase 2 → 2º correo)
9. Mermaid sequenceDiagram (spec §8 — copy)
10. Cliente HTTP requirements (curl, Idempotency-Key, no Green)
11. Checklist implementación back-office (checkbox list, not waapi org)
12. Referencias cruzadas to contract + lebytek-implementation-real.md

**Must NOT contain:** "lebytek.com no se integra con api", `organizations` as primary table, waapi as orchestrator.

- [ ] **Step 2: Replace `role-delegation-waapi.md` with stub**

```markdown
# Deprecated — use role-delegation-lebytek-api.md

> **Este archivo está obsoleto** (modelo waapi-orquestador).  
> Documento canónico: **[role-delegation-lebytek-api.md](role-delegation-lebytek-api.md)**
```

Delete all other content from the file.

- [ ] **Step 3: Verify**

```bash
rg "organizations|waapi_org_|no se integra con api" docs/integration/role-delegation-lebytek-api.md
rg "dom_mkt_leads|lebytek_lead_" docs/integration/role-delegation-lebytek-api.md
wc -l docs/integration/role-delegation-waapi.md
```

Expected: first **no matches** on new file; second **matches**; stub **≤ 6 lines**.

- [ ] **Step 4: Commit**

```bash
git add docs/integration/role-delegation-lebytek-api.md docs/integration/role-delegation-waapi.md
git commit -m "docs: add lebytek.com role delegation; stub legacy waapi doc"
```

---

### Task 4: Implementation guide (new file + stub)

**Files:**
- Create: `docs/integration/lebytek-implementation-real.md`
- Modify: `docs/integration/waapi-implementation-real.md` (replace with stub)

**Interfaces:**
- Consumes: `role-delegation-lebytek-api.md`, contract
- Produces: Framework-oriented guide (no Laravel)

- [ ] **Step 1: Create `docs/integration/lebytek-implementation-real.md`**

Structure (write full prose + code sketches):

```markdown
# lebytek.com — implementación real (espejo del contrato api)

Guía operativa para **Lebytek_Framework** (`lebytek.com` VPS). Repo: branch `feature/backoffice-api-integration`.

## 1. Qué ya existe en api (no reimplementar)
[Table: POST /tenants, health, idempotency — back-office only orchestrates]

## 2. Variables .env
LEBYTEK_API_URL, LEBYTEK_API_TOKEN, LEBYTEK_API_TIMEOUT, LEBYTEK_API_RETRY_MAX, LEBYTEK_API_RETRY_DELAY_MS
MAIL_* for 2º correo

## 3. Migración dom_mkt_leads
[SQL from role-delegation doc]

## 4. LebytekApiClient (curl)
Namespace: App\Infrastructure\Integrations\LebytekApi\LebytekApiClient
Methods: health(), provisionTenant(name, slug, externalRef), getTenant(publicId)
Headers: Authorization, Accept, Idempotency-Key on POST/PATCH
NO Illuminate\, NO Eloquent

## 5. LeadApiProvisioningService
Triggered on: admin approves lead (CRUD action / handler — NOT org registration)
externalRef: lebytek_lead_{id}
Persist: api_tenant_public_id, api_provisioned_at, api_provision_error

## 6. Segundo correo (v1)
Template fields: client name, LEBYTEK_API token (after POST /tokens exists), api base URL
waapi link: omit in v1

## 7. Legacy Green path (disable)
GREEN_API_ENABLED=false; DemoProvisioningService / Partner local — off when api wired
Reference: config/integrations.php, modules/integrations.php

## 8. Health check cron
php scripts/ or dedicated CLI — NOT artisan

## 9. Manual E2E curl commands
[same as old doc but lebytek_lead_* externalRef]

## 10. Checklist
[checkbox list for back-office implementation — future code task]

## 11. Prohibiciones
No green-api.com in app/, no webhooks Green, no LEBYTEK_API_TOKEN in git
```

Include minimal **curl-based** `LebytekApiClient` skeleton (~80 lines PHP) without Laravel imports — adapt from old waapi-implementation-real §4.2 but use `Ramsey\Uuid\Uuid` or `sprintf` UUID if no Str facade.

- [ ] **Step 2: Stub `waapi-implementation-real.md`**

```markdown
# Deprecated — use lebytek-implementation-real.md

> **Este archivo está obsoleto** (guía waapi/Laravel).  
> Documento canónico: **[lebytek-implementation-real.md](lebytek-implementation-real.md)**
```

- [ ] **Step 3: Verify**

```bash
rg "Organization|Eloquent|php artisan organizations|Illuminate\\\\Support" docs/integration/lebytek-implementation-real.md
rg "LeadApiProvisioningService|dom_mkt_leads|LebytekApiClient" docs/integration/lebytek-implementation-real.md
```

Expected: first **no matches**; second **matches**.

- [ ] **Step 4: Commit**

```bash
git add docs/integration/lebytek-implementation-real.md docs/integration/waapi-implementation-real.md
git commit -m "docs: add lebytek.com implementation guide; stub legacy waapi guide"
```

---

### Task 5: VPS checklist and integration README

**Files:**
- Modify: `docs/integration/VPS_CHECKLIST.md`
- Create: `docs/integration/README.md`

**Interfaces:**
- Consumes: spec §6.4, §6.7
- Produces: index file linked from ARCHITECTURE

- [ ] **Step 1: Rewrite `docs/integration/VPS_CHECKLIST.md` sections**

**Add new section** after api block:

```markdown
## lebytek.com (VPS target)

Ruta: `/home/lebytek/htdocs/lebytek.com`  
Usuario CloudPanel: `lebytek`  
Branch: `feature/backoffice-api-integration` (until merge)

### Código
- [ ] Clone/pull Lebytek_Framework feature branch
- [ ] `composer install --no-dev`
- [ ] Document root → `public/`
- [ ] `.env`: DB, MAIL_*, LEBYTEK_API_URL, LEBYTEK_API_TOKEN

### BD
- [ ] Installer or `php scripts/migrate.php` + seed
- [ ] Marketing module + dom_mkt_leads

### Smoke
- [ ] Landing `/` loads
- [ ] `/admin/login` loads
- [ ] `GET /api/v1/health` from server using LEBYTEK_API_TOKEN → 200

### DNS
- [ ] **Do not** point lebytek.com DNS here until E2E green (FTP legacy still live)
```

**Update api section:** `WAAPI_SERVICE_EMAIL` note; token primary consumer = lebytek.com `.env`.

**Update waapi section:** heading `## waapi.lebytek.com (congelado — panel fase final)`; mark integration checklist items as `[ ] diferido`.

**Update marketing section:** remove "Sin lógica WhatsApp ni tokens api"; replace with "Integración api vía back-office en VPS target".

- [ ] **Step 2: Create `docs/integration/README.md`**

```markdown
# Integración api.lebytek.com

| Archivo | Rol |
|---------|-----|
| [waapi-api-contract.md](waapi-api-contract.md) | Contrato HTTP v1 (nombre legacy; contenido canónico) |
| [role-delegation-lebytek-api.md](role-delegation-lebytek-api.md) | Responsabilidades lebytek.com ↔ api |
| [lebytek-implementation-real.md](lebytek-implementation-real.md) | Guía operativa Framework (back-office) |
| [prompt2-review-pre-waapi.md](prompt2-review-pre-waapi.md) | Auditoría histórica núcleo api |
| [VPS_CHECKLIST.md](VPS_CHECKLIST.md) | Deploy smoke tests |
| [role-delegation-waapi.md](role-delegation-waapi.md) | ⚠️ Stub → usar lebytek-api |
| [waapi-implementation-real.md](waapi-implementation-real.md) | ⚠️ Stub → usar lebytek-implementation-real |

Spec: [../superpowers/specs/2026-06-30-integration-docs-alignment-design.md](../superpowers/specs/2026-06-30-integration-docs-alignment-design.md)
```

- [ ] **Step 3: Verify**

```bash
rg "lebytek.com \(VPS target\)|congelado|Sin lógica WhatsApp" docs/integration/VPS_CHECKLIST.md
test -f docs/integration/README.md && echo OK
```

- [ ] **Step 4: Commit**

```bash
git add docs/integration/VPS_CHECKLIST.md docs/integration/README.md
git commit -m "docs: update VPS checklist and add integration README index"
```

---

### Task 6: Historical banner + repo-wide link sweep

**Files:**
- Modify: `docs/integration/prompt2-review-pre-waapi.md` (banner only)
- Modify: any remaining stale links (grep-driven)

**Interfaces:**
- Consumes: Tasks 1–5 file names
- Produces: zero stale primary references in `docs/` and `CLAUDE.md`

- [ ] **Step 1: Add banner to `prompt2-review-pre-waapi.md`**

Insert after title:

```markdown
> **Documento histórico** — auditoría del núcleo api antes del pivot a lebytek.com como consumidor.  
> Consumidor actual: **back-office lebytek.com**. Ver [role-delegation-lebytek-api.md](role-delegation-lebytek-api.md).
```

Do not rewrite body.

- [ ] **Step 2: Repo-wide stale link sweep**

```bash
rg "role-delegation-waapi\.md|waapi-implementation-real\.md" docs/ CLAUDE.md README.md --glob '!docs/integration/role-delegation-waapi.md' --glob '!docs/integration/waapi-implementation-real.md' --glob '!docs/integration/README.md'
```

Fix every match to new filenames (except intentional stubs/README).

Check `docs/superpowers/specs/2026-06-29-green-api-partner-instances-design.md` footer if it links old delegation doc.

- [ ] **Step 3: Acceptance grep (spec §9)**

```bash
rg "no se integra con api" docs/integration/ docs/ARCHITECTURE.md
rg "waapi_org_" docs/integration/ --glob '!*waapi-api-contract*' 
rg "organizations\.api_tenant" docs/integration/
```

Expected: **no matches** in primary docs (contract may mention legacy alias once — allowed if labeled legacy).

- [ ] **Step 4: Commit**

```bash
git add docs/integration/prompt2-review-pre-waapi.md docs/ CLAUDE.md README.md
git commit -m "docs: historical banner and fix stale integration cross-links"
```

---

### Task 7: Mirror to Lebytek_Framework

**Files:**
- Copy: `WhatsApiLebytek/docs/integration/*` → `Lebytek_Framework/docs/integration/`
- Modify: `Lebytek_Framework/CLAUDE.md` (integration links if present)
- Modify: `Lebytek_Framework/docs/integration/README.md` (one line: source of truth = api repo)

**Interfaces:**
- Consumes: all files from Tasks 1–6 in WhatsApiLebytek
- Produces: identical integration folder in Framework branch

- [ ] **Step 1: Copy integration docs**

From workspace (adjust paths if needed):

```powershell
Copy-Item -Path "WhatsApiLebytek\docs\integration\*" -Destination "Lebytek_Framework\docs\integration\" -Force -Recurse
```

- [ ] **Step 2: Add source note to Framework `docs/integration/README.md`**

Append:

```markdown
> **Fuente de verdad:** editar primero en `Parzival2103/WhatsApiLebytek/docs/integration/`, luego espejar aquí.
```

- [ ] **Step 3: Update `Lebytek_Framework/CLAUDE.md`**

Replace integration bullet list to match api `CLAUDE.md` links (lebytek-api + lebytek-implementation-real).

- [ ] **Step 4: Verify diff parity**

```bash
# Compare file lists (manual or diff tool)
ls docs/integration/
```

Both repos should list: `README.md`, `waapi-api-contract.md`, `role-delegation-lebytek-api.md`, `lebytek-implementation-real.md`, stubs, `VPS_CHECKLIST.md`, `prompt2-review-pre-waapi.md`.

- [ ] **Step 5: Commit in each repo**

WhatsApiLebytek (if spec status update desired):

```bash
# optional: mark spec approved
# edit docs/superpowers/specs/2026-06-30-integration-docs-alignment-design.md Estado: Aprobado
```

Lebytek_Framework:

```bash
cd Lebytek_Framework
git add docs/integration/ CLAUDE.md
git commit -m "docs: mirror aligned integration docs from WhatsApiLebytek"
git push origin feature/backoffice-api-integration
```

WhatsApiLebytek:

```bash
cd WhatsApiLebytek
git push origin feature/green-api-partner-instances
# or main if docs PR targets main — confirm with team
```

---

## Spec self-review

| Spec requirement | Task |
|------------------|------|
| Enfoque B rename + rewrite | 3, 4, stubs |
| Vocabulary `lebytek_lead_{id}` | 2, 3, 4 |
| 2º correo token mandatory, waapi optional | 2, 4 |
| WAAPI_SERVICE_* documented | 2 |
| POST /tokens pending labeled | 2 |
| ARCHITECTURE + VPS updated | 1, 5 |
| prompt2 banner | 6 |
| Framework mirror | 7 |
| CLAUDE links | 1, 7 |
| No code changes | all tasks |

**Placeholder scan:** none — all steps have concrete paths and verification commands.

---

## After this plan

Next specs (out of scope here):

1. api: `POST /tenants/{publicId}/tokens` + Fase 2 instances (`2026-06-29-green-api-partner-instances-design.md`)
2. Framework: `LebytekApiClient` + lead migration + deploy VPS
3. E2E + DNS cutover
