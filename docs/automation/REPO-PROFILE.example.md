# REPO-PROFILE — plantilla

Copia este archivo a `docs/automation/REPO-PROFILE.md` en el repo destino y
rellena cada campo. Las nueve automations del kit **obligan** a leer este
archivo al inicio del run.

---

## Identidad

| Clave | Valor | Notas |
|-------|-------|-------|
| `TARGET_REPO` | `owner/repo` | Repo donde corre la automation |
| `BASE_BRANCH` | `main` | Rama canónica / deploy |
| `PRODUCT_NAME` | Nombre corto del producto | Aparece en reportes y WhatsApp |
| `PRODUCT_ROLE` | Una frase | Qué es este repo (API, package, app, …) |
| `DEFAULT_BRANCH_SHA_CMD` | `git rev-parse --verify origin/main` | Ajustar si `BASE_BRANCH` ≠ `main` |

## Artefactos

| Clave | Valor por defecto |
|-------|-------------------|
| `AUDIT_DIR` | `docs/audits` |
| `AUDIT_FILENAME_PATTERN` | `YYYY-MM-DD-auditoria-tecnica-diaria.md` |
| `SPEC_DIR` | `docs/superpowers/specs` |
| `PLAN_DIR` | `docs/superpowers/plans` |
| `PLAN_ARCHIVE_DIR` | `docs/archive/superpowers/plans` |
| `REPORTS_DIR` | `docs/automation-reports` |
| `AUDIT_BRANCH_PREFIX` | `automation/audit-` |
| `SPEC_BRANCH_PREFIX` | `automation/spec-` |
| `IMPL_BRANCH_PREFIX` | `feat/` |

## Preflight legacy (opcional)

Lista ordenada de refs completamente calificadas. El primer candidato que
resuelva con `git rev-parse --verify --quiet '<ref>^{commit}'` es
`<LEGACY_REF>`. Si **ninguno** resuelve tras un fetch verificado → comprobación
vacua, continuar.

```
LEGACY_REFS:
  - (vacío = sin historial legacy que vigilar)
```

Ejemplo Framework:

```
LEGACY_REFS:
  - refs/tags/archive/backoffice-api-integration
  - refs/remotes/origin/feature/backoffice-api-integration
```

## Verdad de producto / ownership

Lista de repos hermanos (lectura vía `gh`, sin checkout):

```
SISTER_REPOS:
  - repo: owner/other
    branch: main
    role: descripción breve
    owns: qué código/negocio les pertenece
```

Reglas de ownership en prosa (qué NUNCA debe vivir en este repo):

```
OWNERSHIP_RULES: |
  - …
```

## Alcance de auditoría (etapa 00)

Bullet list de áreas a revisar (rutas, módulos, riesgos). Adapta al producto.

```
AUDIT_SCOPE:
  - …
```

## Verificación / tests

| Clave | Valor |
|-------|-------|
| `PRIMARY_TEST_CMD` | Comando principal (debe descubrir ≥1 test) |
| `FOCUSED_TEST_CMDS` | Lista de subsets útiles para planes |
| `BUILD_CMDS` | Builds opcionales (`npm run build`, etc.) |
| `PHP_MIN_VERSION` | p. ej. `8.3` o `n/a` |

## Superficie UX (etapa 03)

| Clave | Valor |
|-------|-------|
| `UX_SURFACE` | `api-http` \| `admin-ui` \| `package-admin` \| `none` |
| `UX_CHECKLIST` | Bullets concretos para el pase 03 |

Si `UX_SURFACE` = `none`, la etapa 03 declara «sin superficie UI» y hace
carry-forward UX.

## Lecturas obligatorias antes de planificar (etapa 04)

```
PLAN_REQUIRED_READS:
  - CLAUDE.md
  - AGENTS.md
  - …
```

## WhatsApp (etapas 05 y 08)

| Clave | Valor |
|-------|-------|
| `WHATSAPP_ENABLED` | `true` \| `false` |
| `WHATSAPP_API_URL_ENV` | `LEBYTEK_API_URL` |
| `WHATSAPP_API_TOKEN_ENV` | `LEBYTEK_API_TOKEN` |
| `WHATSAPP_INSTANCE_ENV` | `LEBYTEK_INSTANCE_PUBLIC_ID` |
| `WHATSAPP_TO_ENV` | `AUDIT_PLAN_WHATSAPP_TO` |
| `WHATSAPP_IDEMPOTENCY_PREFIX_PLAN` | `audit-plan` |
| `WHATSAPP_IDEMPOTENCY_PREFIX_CLOSURE` | `audit-closure` |

## Prohibiciones extra del repo

```
EXTRA_PROHIBITIONS:
  - No deploy VPS / SSH desde automation
  - …
```
