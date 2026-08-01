# Cursor Automations — WhatsApiLebytek

Cadena portable de **nueve etapas** (00–08) para auditoría, spec, plan,
readiness, ejecución y cierre. **No reemplaza** deploy ni operación en VPS.

## Principios

1. **Human-in-the-loop** — producción y merges críticos requieren persona.
2. **Reportes versionados** — salida en `docs/automation-reports/` y artefactos
   bajo `docs/audits/`, `docs/superpowers/specs/`, `docs/superpowers/plans/`.
3. **Riesgo proporcional** — fix pequeño → PR; riesgo medio/alto → issue o solo
   reporte.
4. **Perfil obligatorio** — cada prompt lee `REPO-PROFILE.md` antes de actuar.

## Archivos de contexto

| Archivo | Uso |
|---------|-----|
| `AGENTS.md` | Entrada rápida para agentes |
| `docs/automation/CONTEXT.md` | Stack, módulos, integraciones, checklist VPS |
| `docs/automation/REPO-PROFILE.md` | Perfil activo del repo (TARGET_REPO, tests, ownership) |
| `docs/automation/KIT-README.md` | Mapa del kit portable y invariantes |
| `docs/automation/AUTOMATION-0*.md` | Prompt canónico por etapa (bloque `## Prompt`) |
| `.cursor/rules/automation-safety.mdc` | Reglas Cursor en sesiones locales |
| `docs/automation-reports/` | Salida de reportes (readiness, closure, etc.) |

## Cadena 00–08

### Fase 1 — Audit → spec → plan (00–05)

| # | Archivo | Entrega |
|---|---------|---------|
| 00 | `AUTOMATION-00-daily-audit.md` | reporte auditoría + PR draft `docs(audit):` |
| 01 | `AUTOMATION-01-daily-spec.md` | design spec |
| 02 | `AUTOMATION-02-audit-tech-debt.md` | pase deuda sobre el spec |
| 03 | `AUTOMATION-03-audit-ux.md` | pase UX/compat + PR diario + merge audit |
| 04 | `AUTOMATION-04-plan-writer.md` | reconciliación plan activo + plan del día |
| 05 | `AUTOMATION-05-wha-notify.md` | aviso WhatsApp «plan listo» |

### Fase 2 — Readiness → ejecución → cierre (06–08)

| # | Archivo | Entrega |
|---|---------|---------|
| 06 | `AUTOMATION-06-plan-readiness-gate.md` | `docs/automation-reports/*-plan-readiness.md` |
| 07 | `AUTOMATION-07-plan-executor.md` | implementación (`feat/*` + PR producto) |
| 08 | `AUTOMATION-08-plan-closure.md` | merges/cierre + reporte closure + WhatsApp |

Instalación detallada: `INSTALL-WhatsApiLebytek.md`. Perfil plantilla:
`REPO-PROFILE.example.md`.

## Repos hermanos

| Repo | Rama | Rol |
|------|------|-----|
| **WhatsApiLebytek** (este) | `main` | API Laravel `api.lebytek.com` |
| Lebytek_Framework | `main` | Paquete Composer `lebytek/framework` |
| Lebytek_Portal | `main` | lebytek.com / waapi.lebytek.com |

`feature/backoffice-api-integration` es evidencia histórica. No usar como base
de auditorías, specs o planes.

## Cómo crear en Cursor

1. Abrir **Agents Window** (requerido para el editor de Automations).
2. Crear **nueve** automations (00–08) → pegar el bloque `## Prompt` de cada
   archivo.
3. Repo: `Parzival2103/WhatsApiLebytek`, branch: `main`.
4. Secrets WhatsApp (05 y 08): `LEBYTEK_API_URL`, `LEBYTEK_API_TOKEN`,
   `LEBYTEK_INSTANCE_PUBLIC_ID`, `AUDIT_PLAN_WHATSAPP_TO`.
5. Permisos: Git write en 07–08; `gh pr merge` en 03 (audit) y 08 (cierre).
6. **No** habilitar deploy VPS ni edición de `.env` prod.

## Legacy

El prompt antiguo «Daily SaaS Technical Audit» vive en
`archive/AUTOMATION-01-daily-audit.LEGACY.md`. No configurar dos cadenas
concurrentes en Cursor.

## Salida de reportes

```
docs/automation-reports/
docs/audits/
docs/superpowers/specs/
docs/superpowers/plans/
docs/archive/superpowers/plans/
```
