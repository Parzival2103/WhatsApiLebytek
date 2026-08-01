# REPO-PROFILE — WhatsApiLebytek

Perfil listo para instalar como `docs/automation/REPO-PROFILE.md` en
`Parzival2103/WhatsApiLebytek`.

---

## Identidad

| Clave | Valor |
|-------|-------|
| `TARGET_REPO` | `Parzival2103/WhatsApiLebytek` |
| `BASE_BRANCH` | `main` |
| `PRODUCT_NAME` | WhatsApiLebytek |
| `PRODUCT_ROLE` | API Laravel multi-tenant WhatsApp (Green API) en `api.lebytek.com` |
| `DEFAULT_BRANCH_SHA_CMD` | `git rev-parse --verify origin/main` |

## Artefactos

| Clave | Valor |
|-------|-------|
| `AUDIT_DIR` | `docs/audits` |
| `AUDIT_FILENAME_PATTERN` | `YYYY-MM-DD-auditoria-tecnica-diaria.md` |
| `SPEC_DIR` | `docs/superpowers/specs` |
| `PLAN_DIR` | `docs/superpowers/plans` |
| `PLAN_ARCHIVE_DIR` | `docs/archive/superpowers/plans` |
| `REPORTS_DIR` | `docs/automation-reports` |
| `AUDIT_BRANCH_PREFIX` | `automation/audit-` |
| `SPEC_BRANCH_PREFIX` | `automation/spec-` |
| `IMPL_BRANCH_PREFIX` | `feat/` |

## Preflight legacy

```
LEGACY_REFS:
  # Vacío a propósito: este repo no usa el historial
  # feature/backoffice-api-integration del Framework.
  # Si en el futuro existiera un tag/rama a vigilar, listarlo aquí
  # con refs completamente calificadas.
```

Comprobación vacua tras fetch verificado → continuar.

## Verdad de producto / ownership

```
SISTER_REPOS:
  - repo: Parzival2103/Lebytek_Framework
    branch: main
    role: Paquete Composer lebytek/framework (plataforma)
    owns: Kernel, RBAC genérico, CRUD Engine, Payments genérico; NO negocio Marketing
  - repo: Parzival2103/Lebytek_Portal
    branch: main
    role: App desplegable lebytek.com / waapi.lebytek.com
    owns: Marketing, leads, membresías, landing, orquestación api vía LebytekApiClient
```

```
OWNERSHIP_RULES: |
  - Este repo es el motor WhatsApp: API /api/v1, webhooks, colas Horizon, Green API.
  - No implementar Marketing/CRM/membresías aquí (eso es Portal).
  - No parchear ni path-autoload el Framework; Portal lo consume por Composer.
  - feature/backoffice-api-integration (Framework) es evidencia histórica; nunca
    base de auditoría, spec, plan, implementación ni merge en ningún repo hermano.
  - vendor/ es de sólo lectura.
  - Deploy VPS (ssh lebytek-vps) queda fuera de las automations desatendidas;
    solo sesión interactiva autorizada por humano.
```

## Alcance de auditoría (etapa 00)

```
AUDIT_SCOPE:
  - Cambios recientes en main (commits y PRs desde la auditoría anterior)
  - Contrato API v1: routes/api.php, Controllers Api/V1, Form Requests
  - Webhooks Green API: firma, idempotencia, IncomingWebhookController
  - RBAC API: config/permissions.php, middleware ensure.api.permission
  - Jobs / Horizon: colas transactional, campaigns, provisioning; rate limits Redis
  - Integración Green API (Services/GreenApi) y secretos no filtrados
  - Tokens Sanctum / TenantTokenService / provisioning
  - Migraciones y riesgos destructivos
  - Admin Inertia (routes/web.php, resources/js) si el cambio lo toca
  - Tests Pest / CI (.github/workflows), Scribe OpenAPI drift
  - Documentación vs código (ARCHITECTURE, DEPLOY, integration contracts)
  - Riesgos de deploy api.lebytek.com (sin ejecutar deploy)
```

## Verificación / tests

| Clave | Valor |
|-------|-------|
| `PRIMARY_TEST_CMD` | `composer test` |
| `FOCUSED_TEST_CMDS` | `./vendor/bin/pest --filter=Api`, `./vendor/bin/pest tests/Feature` |
| `BUILD_CMDS` | `npm run build` (si el plan toca frontend), `php artisan scribe:generate` (si toca contrato docs) |
| `PHP_MIN_VERSION` | `8.3` |

## Superficie UX (etapa 03)

| Clave | Valor |
|-------|-------|
| `UX_SURFACE` | `api-http` (+ admin Inertia cuando el spec lo toque) |

```
UX_CHECKLIST:
  - Compatibilidad: PHP ≥ 8.3; Laravel lock; Redis/Horizon requeridos en prod
  - Contrato HTTP: códigos de error accionables; OpenAPI/Scribe alineado con rutas
  - Auth: Bearer/Sanctum; mensajes que digan qué falta (token, permiso, tenant)
  - Idempotencia webhooks: comportamiento ante reintentos documentado en spec
  - Admin Inertia (si aplica): login/dashboard usable 320–768px; estados vacío/error/carga
  - Sin superficie UI en un spec de infra: declarar «sin superficie UI» + carry-forward
```

## Lecturas obligatorias antes de planificar (etapa 04)

```
PLAN_REQUIRED_READS:
  - CLAUDE.md
  - AGENTS.md
  - docs/ARCHITECTURE.md
  - docs/automation/CONTEXT.md
  - docs/automation/REPO-PROFILE.md
  - docs/integration/waapi-api-contract.md
  - docs/DEPLOY.md
```

## WhatsApp (etapas 05 y 08)

| Clave | Valor |
|-------|-------|
| `WHATSAPP_ENABLED` | `true` |
| `WHATSAPP_API_URL_ENV` | `LEBYTEK_API_URL` |
| `WHATSAPP_API_TOKEN_ENV` | `LEBYTEK_API_TOKEN` |
| `WHATSAPP_INSTANCE_ENV` | `LEBYTEK_INSTANCE_PUBLIC_ID` |
| `WHATSAPP_TO_ENV` | `AUDIT_PLAN_WHATSAPP_TO` |
| `WHATSAPP_IDEMPOTENCY_PREFIX_PLAN` | `waapi-audit-plan` |
| `WHATSAPP_IDEMPOTENCY_PREFIX_CLOSURE` | `waapi-audit-closure` |

Nota: el propio producto es `api.lebytek.com`; el aviso usa la misma API de
mensajes. No hardcodear tokens; no imprimirlos en logs.

## Prohibiciones extra del repo

```
EXTRA_PROHIBITIONS:
  - No SSH a lebytek-vps ni git pull en prod desde automation
  - No php artisan migrate --force en producción
  - No reiniciar Horizon / supervisor desde automation
  - No exponer apiTokenInstance crudo de Green API en reportes, PRs ni WhatsApp
  - No mergear PRs de Framework legacy feature desde este pipeline
```
