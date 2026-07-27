# Cursor Automations — ecosistema Lebytek

Sistema de automatizaciones para revisión, auditoría y reportes. **No reemplaza** deploy ni operación en VPS.

## Principios

1. **Human-in-the-loop** — producción y merges críticos requieren persona.
2. **Reportes versionados** — salida en `docs/automation-reports/` dentro del repo.
3. **Riesgo proporcional** — fix pequeño → PR; riesgo medio/alto → issue o solo reporte.
4. **Un repo por automation** — cada automation de Cursor hace checkout de un repo; el prompt puede referenciar hermanos vía GitHub CLI.

## Repos y ramas

| Repo | Automation primary | Base obligatoria | Rol |
|------|-------------------|------------------|-----|
| WhatsApiLebytek | Sí (API) | `main` | API Laravel desplegable |
| Lebytek_Framework | Sí (package) | `main` | Plataforma Composer, no sitio |
| Lebytek_Portal | Sí (negocio) | `main` | lebytek.com/waapi desplegable |
| docs.lebytek.com | Opcional (sync drift) | `main` | Mirror documental |

`feature/backoffice-api-integration` es evidencia histórica del monolito. No
debe configurarse como rama de checkout de auditorías, specs o planes.

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
| 02 | Audit to FPS Spec | [Prompt en Framework](https://github.com/Parzival2103/Lebytek_Framework/blob/main/docs/automation/AUTOMATION-02-audit-to-spec.md) |
| 03 | FPS Spec to Implementation Plan | [Prompt en Framework](https://github.com/Parzival2103/Lebytek_Framework/blob/main/docs/automation/AUTOMATION-03-spec-to-plan.md) |
| 04 | VPS health digest (read-only) | Pendiente |
| 05 | Docs drift detector | Pendiente |

## Cómo crear en Cursor

1. Abrir **Agents Window** (requerido para abrir el editor de Automations).
2. Nueva automation → trigger **cron** → pegar prompt desde `AUTOMATION-01-daily-audit.md`.
3. Repo y rama: usa una automation separada por repo, siempre sobre `main`.
4. Habilitar memoria del agente si quieres continuidad entre días.
5. **No** habilitar acciones que desplieguen sin confirmación.
6. En Framework, copiar los prompts canónicos de su propio
   `docs/automation/`; no reutilizar ramas de una etapa anterior.

## Salida de reportes

```
docs/automation-reports/
├── daily-audit/
│   ├── TEMPLATE.md
│   └── YYYY-MM-DD.md
├── pr-review/          # futuro
└── post-merge/         # futuro
```
