# Cursor Automations — ecosistema Lebytek

Sistema de automatizaciones para revisión, auditoría y reportes. **No reemplaza** deploy ni operación en VPS.

## Principios

1. **Human-in-the-loop** — producción y merges críticos requieren persona.
2. **Reportes versionados** — salida en `docs/automation-reports/` dentro del repo.
3. **Riesgo proporcional** — fix pequeño → PR; riesgo medio/alto → issue o solo reporte.
4. **Un repo por automation** — cada automation de Cursor hace checkout de un repo; el prompt puede referenciar hermanos vía GitHub CLI.

## Repos y ramas

| Repo | Automation primary | Rama default | Rama VPS |
|------|-------------------|--------------|----------|
| WhatsApiLebytek | Sí (API) | `main` | `main` |
| Lebytek_Framework | Segunda automation (recomendado) | `main` | `feature/backoffice-api-integration` |
| docs.lebytek.com | Opcional (sync drift) | `main` | `main` |

## Archivos de contexto

| Archivo | Uso |
|---------|-----|
| `AGENTS.md` | Entrada rápida para agentes |
| `docs/automation/CONTEXT.md` | Stack, módulos, integraciones, checklist VPS |
| `docs/automation/AUTOMATION-*.md` | Prompt canónico por automation |
| `.cursor/rules/automation-safety.mdc` | Reglas Cursor en sesiones locales |
| `docs/automation-reports/` | Salida de reportes |

## Roadmap de automatizaciones

| ID | Nombre | Estado |
|----|--------|--------|
| 01 | Daily SaaS Technical Audit | Definido — ver `AUTOMATION-01-daily-audit.md` (máx. **1 PR abierta**; lee PRs previas y cierra duplicados) |
| 02 | PR Review Gate | Pendiente |
| 03 | Post-merge regression digest | Pendiente |
| 04 | VPS health digest (read-only) | Pendiente |
| 05 | Docs drift detector | Pendiente |

## Cómo crear en Cursor

1. Abrir **Agents Window** (requerido para abrir el editor de Automations).
2. Nueva automation → trigger **cron** → pegar prompt desde `AUTOMATION-01-daily-audit.md`.
3. Repo: `Parzival2103/WhatsApiLebytek`, rama `main`.
4. Habilitar memoria del agente si quieres continuidad entre días.
5. **No** habilitar acciones que desplieguen sin confirmación.

## Salida de reportes

```
docs/automation-reports/
├── daily-audit/
│   ├── TEMPLATE.md
│   └── YYYY-MM-DD.md
├── pr-review/          # futuro
└── post-merge/         # futuro
```
