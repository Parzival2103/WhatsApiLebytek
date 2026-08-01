# Automation Kit — prompts genéricos portables

Kit portable de la cadena diaria de 9 etapas (audit → spec → deuda → UX → plan →
WhatsApp → readiness → ejecución → cierre). **Vive en este repo**
(`WhatsApiLebytek/docs/automation/`): mismos invariantes que la cadena de
producción de `Lebytek_Framework`, sin acoplar el prompt al paquete Composer.

Históricamente se originó en Framework PR #65; el destino canónico es solo este
API.

## Qué incluye

| Ruta | Rol |
|------|-----|
| `REPO-PROFILE.example.md` | Plantilla de perfil; cópiala como `REPO-PROFILE.md` en el repo destino |
| `profiles/WhatsApiLebytek.md` | Perfil listo para `Parzival2103/WhatsApiLebytek` |
| `AUTOMATION-00` … `AUTOMATION-08` | Prompts canónicos genéricos (bloque `## Prompt`) |
| Este README | Instalación y mapa de etapas |

## Instalación (este repo)

El kit ya está instalado bajo `docs/automation/`. Para reinstalar o clonar a
otro producto, copia los prompts + perfil y ajusta `REPO-PROFILE.md`.

1. Perfil activo: `docs/automation/REPO-PROFILE.md` (desde
   `profiles/WhatsApiLebytek.md` o `REPO-PROFILE.example.md`).
2. Asegura carpetas de artefactos (crear vacías o con `.gitkeep` si no existen):
   - `docs/audits/`
   - `docs/superpowers/specs/`
   - `docs/superpowers/plans/`
   - `docs/automation-reports/`
   - `docs/archive/superpowers/plans/` (opcional)
4. En Cursor → Automations: una automation por etapa, repo = destino, branch =
   `BASE_BRANCH` del perfil. Pega el bloque `## Prompt` de cada archivo.
5. Configura secrets WhatsApp (etapas 05 y 08) iguales al kit Framework si usas
   `api.lebytek.com`.
6. Etapas 07–08: Git write; 08 también `gh pr merge` (ver prompt 08).

**Cambiar estos archivos no actualiza una automation ya creada.** Tras editar un
prompt canónico, vuelve a pegar el texto en el editor de Cursor Automations.

## Cadena (igual que Framework)

### Fase 1 — Audit → spec → plan (00–05)

| # | Archivo | Entrega | Rama |
|---|---------|---------|------|
| 00 | `AUTOMATION-00-daily-audit.md` | reporte auditoría + PR draft `docs(audit):` | `automation/audit-YYYY-MM-DD` |
| 01 | `AUTOMATION-01-daily-spec.md` | design spec | `automation/spec-YYYY-MM-DD` |
| 02 | `AUTOMATION-02-audit-tech-debt.md` | pase deuda sobre el spec | `automation/spec-YYYY-MM-DD` |
| 03 | `AUTOMATION-03-audit-ux.md` | pase UX/compat + PR diario + merge audit | `automation/spec-YYYY-MM-DD` |
| 04 | `AUTOMATION-04-plan-writer.md` | reconciliación plan activo + plan del día | `automation/spec-YYYY-MM-DD` |
| 05 | `AUTOMATION-05-wha-notify.md` | aviso WhatsApp «plan listo» | — |

### Fase 2 — Readiness → ejecución → cierre (06–08)

| # | Archivo | Entrega |
|---|---------|---------|
| 06 | `AUTOMATION-06-plan-readiness-gate.md` | `docs/automation-reports/*-plan-readiness.md` |
| 07 | `AUTOMATION-07-plan-executor.md` | implementación (`feat/*` + PR producto) |
| 08 | `AUTOMATION-08-plan-closure.md` | merges/cierre + reporte closure + WhatsApp |

## Invariantes (no negociables)

- Cada etapa lee primero `docs/automation/REPO-PROFILE.md` y enlaza todos los
  valores del perfil. Sin perfil → **STOP**.
- Dos ramas por día: `automation/audit-*` (00) y `automation/spec-*` (01–04),
  nacidas de `origin/<BASE_BRANCH>`.
- Cada etapa commitea **solo** su artefacto; `git status --porcelain` limpio
  antes y después.
- Sin «skip» silencioso: degradación explícita o STOP de preflight.
- En modo degradado no se inventan hallazgos, rutas, PRs, SHAs ni tests.
- Un comando que descubre cero tests no es un gate verde.
- Etapas 00–07 no mergean PRs de producto (08 sí, con CI green y permisos).
- Ninguna etapa despliega, usa SSH, edita `.env` prod ni corre migraciones de
  producción (salvo que el perfil declare lo contrario — por defecto **no**).
- Ciclo de vida Enfoque B: 00 abre PR audit; 03 mergea audit a `main` y abre PR
  spec; 04 entrega plan; 06–08 ejecutan y cierran.

## Relación con `Lebytek_Framework`

| Ubicación | Audiencia |
|-----------|-----------|
| `Lebytek_Framework/docs/automation/` | Cadena de producción del paquete Composer |
| `WhatsApiLebytek/docs/automation/` (aquí) | Cadena portable del API |

Evoluciona ambos en paralelo; cuando un invariante cambie en uno, porta el
cambio al otro según corresponda.

## Perfil WhatsApiLebytek

Ver `profiles/WhatsApiLebytek.md`. Diferencias típicas vs Framework:

- Tests: `composer test` / Pest, no `php tests/run.php`.
- Superficie: API Laravel, Horizon, webhooks, Green API, Sanctum — no `src/` Onion.
- UX (etapa 03): contrato HTTP / OpenAPI / admin Inertia; no dashboard CRUD del paquete.
- Hermanos: Framework + Portal vía `gh` (sin checkout).
- Legacy refs: suele ser lista vacía → comprobación vacua (igual que Framework
  cuando el historial legacy ya no es alcanzable).
