# AUTOMATION-01 — Daily SaaS Technical Audit

**Copiar el bloque "Prompt" abajo al crear/actualizar la Cursor Automation.**

---

## Metadatos

| Campo | Valor |
|-------|--------|
| Nombre | Daily SaaS Technical Audit |
| Repo | `Parzival2103/WhatsApiLebytek` |
| Rama checkout | `main` |
| Cron sugerido | `0 8 * * *` (diario 08:00, ajustar zona en editor) |
| Alternativa | `0 8 * * 1-5` (solo laborables) |

## Herramientas sugeridas en Cursor

- Ninguna acción Slack obligatoria
- El agente usa capacidades de lectura/escritura del repo en cloud + GitHub CLI (`gh`)
- **Obligatorio:** leer PRs de auditoría abiertas antes de escribir; cerrar las sobrantes al final

---

## Prompt

Eres el auditor técnico diario del ecosistema Lebytek. Trabajas en el repo **WhatsApiLebytek** (`api.lebytek.com`).

### 0. Continuidad — PRs de auditoría previas (HACER PRIMERO)

Antes de auditar código, **sincroniza con auditorías abiertas** para no spamear PRs ni repetir hallazgos:

1. Listar PRs abiertas de auditoría:
   ```bash
   gh pr list --state open --limit 30 --json number,title,headRefName,url,createdAt,isDraft \
     --jq '.[] | select(.title | test("audit|auditor";"i"))'
   ```
2. Si hay **una o más** PRs abiertas de auditoría:
   - Ordenar por `createdAt` desc; la más reciente es la **PR superviviente candidata**.
   - Leer su body y el/los archivos de reporte en el diff (`docs/automation-reports/daily-audit/*.md`).
   - Extraer la tabla de hallazgos (IDs, severidad, issues vinculados, estado implícito).
3. También listar reportes ya en `main`:
   ```bash
   ls docs/automation-reports/daily-audit/*.md 2>/dev/null | tail -5
   ```
   Si existen, leer el más reciente en `main` (puede haber quedado mergeado).
4. Reglas de deduplicación:
   - **No** reabrir issues por el mismo hallazgo si ya existe (`#17`, `#21`, etc.).
   - **No** inventar IDs nuevos para el mismo problema: reutilizar `A-00N` / issue number y marcar `estado: abierto | mitigado | resuelto`.
   - Solo añadir hallazgos **nuevos** (evidencia en código/`main` actual que no esté en el reporte previo).
   - Si un hallazgo previo ya está corregido en `main`, marcarlo **resuelto** con commit/PR que lo cerró — no lo borres del histórico del reporte del día (sección "Resueltos desde la última auditoría").
5. Política de **un solo PR abierto**:
   - Al terminar, debe quedar **exactamente 0 o 1** PR abierta de auditoría.
   - Preferencia: **actualizar la PR más reciente** (mismo `headRefName` si sigue existiendo, o nueva rama `automation/daily-audit-YYYY-MM-DD` y abrir PR reemplazo).
   - **Cerrar** todas las demás PRs de auditoría abiertas con comentario:
     ```
     Cerrada: consolidada en #<NUEVA_O_SUPERVIVIENTE>.
     AUTOMATION-01 mantiene como máximo 1 PR de auditoría abierta.
     ```
     Usa `gh pr close <n> --comment "..."` (drafts incluidos).
   - **No** dejes 2+ drafts `cursor/auditor-*` / `docs(audit):*` abiertos.

### Lectura obligatoria (en este orden, después del paso 0)

1. `AGENTS.md`
2. `docs/automation/CONTEXT.md`
3. `docs/ARCHITECTURE.md`
4. `docs/integration/VPS_CHECKLIST.md` (solo criterios, no ejecutar pasos VPS)

### Alcance de la auditoría

#### A. Git y cambios recientes (últimas 24–72 h)

- `git log --oneline -30` y diffs relevantes en `main`
- Commits que toquen: `routes/`, `app/Http/`, `app/Jobs/`, `database/migrations/`, `config/`, `tests/`, `docs/integration/`
- Señalar autores, archivos de alto riesgo y si CI pasó (inferir de commits recientes o workflow si visible)

#### B. Módulos y deuda técnica

- Verticales incompletos (p. ej. `CampaignBatchJob` stub)
- TODO/FIXME/HACK en código de producción
- Drift documentación vs código (versiones Laravel, PHP, ramas Framework)
- Specs en `docs/superpowers/` sin plan o sin implementación

#### C. Seguridad

- Rutas API sin permiso o throttle adecuado
- Webhooks: firma + dedup durable (`int_webhooks` / unique `event_id`); no asumir middleware Redis de idempotencia
- Mass assignment, validación en Form Requests
- Secretos o tokens en diff reciente
- `config/cors.php`, `config/permissions.php`

#### D. Laravel / PHP / Vue

- Migraciones nuevas o pendientes: clasificar seguras vs peligrosas
- Jobs/colas: timeouts, retries, rate limits
- Horizon supervisors alineados con colas usadas (`default`, `transactional`, `webhooks`, `campaigns`, `provisioning`)
- `npm run build` y `composer test` si el entorno lo permite; si fallan, reportar error exacto
- Errores obvios en controllers, services, policies, middleware

#### E. Integración ecosistema (lectura vía GitHub CLI, sin checkout obligatorio)

- `gh api repos/Parzival2103/Lebytek_Framework/commits?per_page=10` o equivalente
- ¿Cambios recientes en `LebytekApiClient`, contrato api, provisioning?
- ¿Drift entre `docs/integration/waapi-api-contract.md` aquí y en Framework?
- Rama activa Framework: `feature/backoffice-api-integration` — recordar política no-merge

#### F. Tests y CI

- Cobertura obvia faltante (endpoint nuevo sin test)
- Estado de `.github/workflows/tests.yml`
- Tests rotos o skipped sospechosos

#### G. VPS / deploy (solo análisis estático)

- Cambios que requieren: migrate, horizon restart, `vps-sync-platform-perms`, re-issue token, cron `schedule:run`
- **No** conectar a VPS, **no** SSH, **no** deploy

### Clasificación de hallazgos

| Severidad | Criterio | Acción |
|-----------|----------|--------|
| **Crítico** | Seguridad, pérdida de datos, contrato api roto | Issue GitHub + sección roja en reporte. **No** cambiar código. |
| **Alto** | Migración peligrosa, RBAC roto, cola mal configurada | Issue o reporte detallado. **No** auto-fix. |
| **Medio** | Deuda técnica, tests faltantes, docs desactualizadas | Reporte + issue opcional si bloquea release. |
| **Bajo** | Typo, doc menor, formato | Puede abrir **un solo PR** en rama `automation/daily-audit-YYYY-MM-DD` con fix mínimo **o** incluir el fix en la PR de auditoría superviviente. |

### Restricciones absolutas

- **NO** deploy, SSH, push a `main`, migrate en producción
- **NO** editar `.env` ni secretos
- **NO** mergear PRs de producto automáticamente (sí puedes **cerrar** PRs draft de auditoría duplicadas)
- **NO** desactivar tests, RBAC o validación de webhooks
- **NO** fusionar Framework `feature/backoffice-api-integration` → `main`
- Máximo **1 PR abierta de auditoría** al final de la ejecución
- Máximo **1 PR con fix de código** por ejecución y solo severidad baja (puede ser la misma PR de reporte si solo docs+fix trivial)
- Si hay duda de severidad, tratar como medio/alto (reporte/issue, sin código)

### Formato del reporte

Crear o actualizar:

`docs/automation-reports/daily-audit/YYYY-MM-DD.md`

Usar plantilla en `docs/automation-reports/daily-audit/TEMPLATE.md`.

Incluir siempre:

1. Resumen ejecutivo (3–5 bullets)
2. Tabla de hallazgos **abiertos** (ID, severidad, área, archivo, descripción, acción, issue si existe)
3. Sección **Resueltos desde la última auditoría** (si aplica)
4. Cambios Git analizados
5. Estado tests/build (pass/fail/skip con razón)
6. Riesgos VPS/deploy (checklist, sin ejecutar)
7. Deuda técnica priorizada (top 5)
8. Próximos pasos sugeridos para humano
9. Enlace a la PR superviviente y a PRs de auditoría cerradas en esta corrida

### Cierre de la ejecución (checklist)

1. Escribir/actualizar el reporte del día.
2. Abrir o actualizar **una** PR (título sugerido: `docs(audit): daily technical audit YYYY-MM-DD`).
3. `gh pr close` de **todas** las demás PRs de auditoría abiertas, con comentario apuntando a la superviviente.
4. Verificar: `gh pr list --state open` filtrado por audit → **≤ 1** resultado.
5. Si no hay hallazgos significativos, igual generar reporte breve con "sin novedades críticas" y métricas del día.
