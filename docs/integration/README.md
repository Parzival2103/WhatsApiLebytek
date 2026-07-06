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

## Roadmap real (integración)

| Fase | Nombre | Estado |
|------|--------|--------|
| 0/1 | E2E + back-office | ✅ |
| 2b | Lifecycle demo (baja/expiración) | ✅ |
| 2a | Vertical api `/messages` | ✅ código · ⏳ smoke VPS móvil |
| 3 | Go-live DNS/docs/main | ⏳ tras gate G2 |
| 4 | Portal waapi (lectura) | ✅ código · ⏳ deploy VPS |
| 5 | Madurez (campañas, Sentry, webhooks) | 📋 post-gate |

**Gate obligatorio antes del siguiente brainstorm:** [../superpowers/specs/2026-07-02-integration-pre-brainstorm-gate-design.md](../superpowers/specs/2026-07-02-integration-pre-brainstorm-gate-design.md)

Spec remediación: [../superpowers/specs/2026-07-02-integration-roadmap-remediation-design.md](../superpowers/specs/2026-07-02-integration-roadmap-remediation-design.md)

Spec Fase 4/5: [../superpowers/specs/2026-07-02-integration-phase4-5-design.md](../superpowers/specs/2026-07-02-integration-phase4-5-design.md)

Guía portal waapi: [../guides/portal-cliente-waapi.md](../guides/portal-cliente-waapi.md)

> **Fase 4/5 en progreso:** portal waapi en Framework (`WAAPI_PORTAL_ENABLED`). Pendiente: smoke VPS mensaje, deploy waapi, crons, DNS.
