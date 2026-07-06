# Gate pre-brainstorm (cierre ops + smoke E2E) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ejecutar el gate G0–G5 del spec `2026-07-02-integration-pre-brainstorm-gate-design.md`: desplegar el código actual en VPS, activar crons, correr el smoke E2E (con evidencia real de mensaje WhatsApp en móvil), hacer go-live condicional y cerrar la deuda documental, para desbloquear el siguiente brainstorm.

**Architecture:** Este NO es un plan de código nuevo. Es un runbook operativo con dos tipos de tarea: (a) **acciones de operador** en el VPS/teléfono (SSH, deploy, crons, escanear QR, DNS) con comandos exactos y salida esperada; (b) **ediciones de repo** que el agente ejecuta (actualizar checklists/docs con la evidencia que reporta el operador, y committear). Cada gate es un checkpoint verificable. El hard-gate (smoke pasos 4, 5, 6) bloquea el go-live.

**Tech Stack:** Laravel 11 (api.lebytek.com), Framework PHP propio Onion (lebytek.com / waapi.lebytek.com), Redis + Horizon, Supervisor, CloudPanel/nginx, cron, Green API, Docsify (docs.lebytek.com), git/GitHub.

## Global Constraints

- **Solo ops/smoke/docs.** Cero código nuevo: sin campañas, sin webhooks inbound, sin facturación, sin BYO credentials, sin cambios arquitectónicos. Cualquier edición de código fuera de un fix de smoke reproducible está fuera de alcance.
- **DNS cutover SOLO después** de que el smoke paso 5 (mensaje WhatsApp recibido en móvil) esté verde. Bloqueante absoluto.
- **NO merge de `feature/backoffice-api-integration` → `main`** salvo orden explícita del usuario (regla `no-merge-framework-main`). Go-live se hace desplegando desde la rama feature.
- **Hard gate:** smoke pasos **4, 5 y 6** deben ser ✅ (con fecha + operador) antes de iniciar G3.
- **Disciplina de evidencia:** ningún `[x]` sin fecha real y operador. No marcar checkboxes "porque el código está listo"; marcar porque se verificó en VPS/prod.
- **Commits mínimos en VPS:** api ≥ `dcc46d0` (messages MVP), Framework ≥ `0868af2` (portal waapi).
- **Rutas VPS:** api → `/home/lebytek-api/htdocs/api.lebytek.com` (user `lebytek-api`); lebytek.com → `/home/lebytek/htdocs/lebytek.com` (user `lebytek`); waapi → mismo árbol Framework en vhost waapi con `WAAPI_PORTAL_ENABLED=true`.
- **El `apiTokenInstance` crudo de Green API nunca** aparece en respuestas, correos ni logs.
- **Fuente de verdad del gate:** `docs/integration/VPS_CHECKLIST.md` (evidencia) + `docs/superpowers/specs/2026-07-02-integration-pre-brainstorm-gate-design.md` (criterios).

---

## File Structure

**Ediciones de repo `WhatsApiLebytek` (agente):**
- `docs/integration/VPS_CHECKLIST.md` — capturar evidencia G0, crontab (G1), tabla smoke 7 pasos (G2), bloque GO/NO-GO (G5), deduplicar sección waapi legacy.
- `docs/integration/README.md` — tabla roadmap (2a smoke, 3 go-live, 4 VPS) + banner cabecera.
- `docs/integration/waapi-api-contract.md` — nota `501` en `PUT /credentials/green-api`.
- `docs/superpowers/specs/2026-07-02-integration-phase4-5-design.md` — banner "superseded" en §0.
- `docs/superpowers/specs/2026-07-02-integration-roadmap-remediation-design.md` — marcar §6.1–6.4 según resultado.

**Acciones de operador (VPS/teléfono, no editan repo):** `git pull`/`composer install`/`php artisan migrate`/restart Horizon en 3 sitios, `crontab`, escaneo QR, recepción WhatsApp, `node scripts/sync-docs.mjs`, DNS cutover.

**Dependencias:** G0 → G1 → G2 → (hard-gate 4/5/6) → {G4 docs, G3 go-live} → G5.

---

### Task 1: G0.1 — Deploy api.lebytek.com

**Files:**
- Modify (evidencia): `docs/integration/VPS_CHECKLIST.md` (sección `## api.lebytek.com`, subsecciones Acceso/Entorno/Migraciones/Servicios/Smoke)

**Interfaces:**
- Consumes: acceso SSH `lebytek-vps`, token de plataforma existente.
- Produces: api VPS en commit ≥ `dcc46d0` con migraciones `int_mensajes` aplicadas y Horizon RUNNING. Otras tareas asumen `/api/v1/health` → 200.

- [ ] **Step 1: [OPERATOR — VPS api] Pull del código**

```bash
ssh lebytek-vps
cd /home/lebytek-api/htdocs/api.lebytek.com
sudo -u lebytek-api git pull origin main
sudo -u lebytek-api git rev-parse --short HEAD
```
Expected: pull sin conflictos; `HEAD` ≥ `dcc46d0` (contiene messages MVP). Si `HEAD` < `dcc46d0`, DETENER: el árbol VPS está atrasado.

- [ ] **Step 2: [OPERATOR — VPS api] Dependencias + migraciones**

```bash
sudo -u lebytek-api composer install --no-dev --optimize-autoloader
sudo -u lebytek-api php artisan migrate --force
```
Expected: `composer` OK; `migrate` aplica migraciones de `int_mensajes` e `int_webhooks` (o "Nothing to migrate" si ya estaban). Sin errores de conexión BD `lebytekapi`.

- [ ] **Step 3: [OPERATOR — VPS api] Reiniciar Horizon**

```bash
sudo supervisorctl restart lebytek-api-horizon:*
sudo -u lebytek-api php artisan horizon:status
```
Expected: `Horizon is running.`

- [ ] **Step 4: [OPERATOR — VPS api] Smoke de arranque**

```bash
curl -sf https://api.lebytek.com/up
curl -sf -H "Authorization: Bearer <LEBYTEK_API_TOKEN>" \
  -H "Accept: application/json" \
  https://api.lebytek.com/api/v1/health
```
Expected: `/up` → 200; `/health` → 200 con `checks.database.ok=true` y `checks.redis.ok=true`.

- [ ] **Step 5: [AGENT] Capturar evidencia en el checklist**

En `docs/integration/VPS_CHECKLIST.md`, sección `## api.lebytek.com`, marcar con fecha real reportada por el operador los ítems: `git pull origin main`, `composer install --no-dev`, `php artisan migrate --force`, `supervisorctl status ... RUNNING`, `/up → 200`, `/api/v1/health con token → 200`. Formato: `- [x] ... (2026-07-0X)`.

- [ ] **Step 6: [AGENT] Commit**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs(gate): G0.1 api deploy evidence (migrate int_mensajes, horizon RUNNING)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```
Expected: commit creado en la rama de trabajo del gate.

---

### Task 2: G0.2 — Deploy lebytek.com (back-office, rama feature)

**Files:**
- Modify (evidencia): `docs/integration/VPS_CHECKLIST.md` (sección `## lebytek.com (VPS target)`)

**Interfaces:**
- Consumes: api VPS operativa (Task 1); `.env` con `LEBYTEK_API_URL` + `LEBYTEK_API_TOKEN`.
- Produces: back-office desplegado desde `feature/backoffice-api-integration`, health contra api = 200. Habilita el smoke de provisioning (Task 5).

- [ ] **Step 1: [OPERATOR — VPS lebytek] Pull rama feature (NO merge a main)**

```bash
cd /home/lebytek/htdocs/lebytek.com
sudo -u lebytek git fetch origin
sudo -u lebytek git checkout feature/backoffice-api-integration
sudo -u lebytek git pull origin feature/backoffice-api-integration
sudo -u lebytek git rev-parse --short HEAD
```
Expected: en rama `feature/backoffice-api-integration`; `HEAD` ≥ `0868af2` (portal waapi). **No** ejecutar `git merge main` ni al revés.

- [ ] **Step 2: [OPERATOR — VPS lebytek] Dependencias + migraciones**

```bash
sudo -u lebytek composer install --no-dev --optimize-autoloader
sudo -u lebytek php scripts/migrate.php
```
Expected: `composer` OK; migraciones del módulo marketing + columnas `api_*` en `dom_mkt_leads` aplicadas (o ya presentes).

- [ ] **Step 3: [OPERATOR — VPS lebytek] Health contra api desde el servidor**

```bash
sudo -u lebytek php scripts/lebytek-api-health.php; echo "exit=$?"
```
Expected: `exit=0` (el script instancia `LebytekApiClient` y llama `/health`; no imprime el token).

- [ ] **Step 4: [OPERATOR — VPS lebytek] Smoke web**

```bash
curl -sfI https://lebytek.com/ | head -1          # o URL de staging si DNS aún no cutover
curl -sfI https://lebytek.com/admin/login | head -1
```
Expected: landing y `/admin/login` → `HTTP/... 200`. (Si DNS todavía apunta al FTP legacy, usar la URL directa del vhost VPS.)

- [ ] **Step 5: [AGENT] Capturar evidencia**

En `docs/integration/VPS_CHECKLIST.md`, sección `## lebytek.com (VPS target)`, marcar con fecha: pull rama feature, `composer install --no-dev`, `.env` DB/MAIL/LEBYTEK_API_*, `GET /api/v1/health → 200`. Dejar sin marcar los ítems DNS (Task 12) y cron (Task 4).

- [ ] **Step 6: [AGENT] Commit**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs(gate): G0.2 lebytek.com deploy evidence (feature branch, api health exit=0)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: G0.3 — Deploy waapi.lebytek.com (portal cliente, modo lectura)

**Files:**
- Modify (evidencia): `docs/integration/VPS_CHECKLIST.md` (sección `## waapi.lebytek.com (panel cliente Fase 4)`)

**Interfaces:**
- Consumes: mismo árbol Framework que lebytek.com; vhost waapi.
- Produces: portal waapi accesible en `/portal/acceso`. Habilita smoke panel (Task 8, hard-gate 6).

- [ ] **Step 1: [OPERATOR — VPS waapi] Configurar entorno del vhost waapi**

En el `.env` del vhost `waapi.lebytek.com`:
```env
WAAPI_PORTAL_ENABLED=true
LEBYTEK_API_URL=https://api.lebytek.com/api/v1
```
Expected: `WAAPI_PORTAL_ENABLED=true`; **NO** debe existir `LEBYTEK_API_TOKEN` de plataforma en este vhost (los clientes usan token por-tenant).

- [ ] **Step 2: [OPERATOR — VPS waapi] Verificar que no hay llamadas Green directas**

```bash
grep -r "green-api" app/Presentation/Controllers/Publico/WaapiPortalController.php | wc -l
```
Expected: `0`.

- [ ] **Step 3: [OPERATOR — VPS waapi] Smoke de acceso**

```bash
curl -sfI https://waapi.lebytek.com/portal/acceso | head -1
```
Expected: `HTTP/... 200`.

- [ ] **Step 4: [AGENT] Capturar evidencia**

En `docs/integration/VPS_CHECKLIST.md`, sección `## waapi.lebytek.com (panel cliente Fase 4)`, marcar con fecha: `WAAPI_PORTAL_ENABLED=true`, `LEBYTEK_API_URL`, `curl /portal/acceso → 200`, `grep green-api → 0 hits`. El ítem "Login con token demo" queda para Task 8.

- [ ] **Step 5: [AGENT] Commit**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs(gate): G0.3 waapi portal deploy evidence (portal enabled, 0 green-api refs)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: G1 — Instalar y capturar crons (health + expire demos)

**Files:**
- Modify (evidencia): `docs/integration/VPS_CHECKLIST.md` (sección `## Remediación — Crons (R2)`)

**Interfaces:**
- Consumes: scripts ya en repo Framework (`scripts/lebytek-api-health.php`, `scripts/expire-api-demos.php`).
- Produces: 2 crons activos; salida de `crontab -l` pegada como evidencia.

- [ ] **Step 1: [OPERATOR — VPS lebytek] Instalar crons del usuario `lebytek`**

```bash
sudo -u lebytek crontab -e
```
Añadir exactamente estas dos líneas:
```cron
*/5 * * * * cd /home/lebytek/htdocs/lebytek.com && php scripts/lebytek-api-health.php >> storage/logs/api-health.log 2>&1
0 3 * * * cd /home/lebytek/htdocs/lebytek.com && php scripts/expire-api-demos.php 30 >> storage/logs/expire-demos.log 2>&1
```
Expected: crontab guardado sin error de sintaxis.

- [ ] **Step 2: [OPERATOR — VPS lebytek] Capturar el crontab**

```bash
sudo -u lebytek crontab -l
```
Expected: la salida muestra las dos líneas. Copiar la salida literal para el checklist.

- [ ] **Step 3: [OPERATOR — VPS lebytek] Verificar que health corre**

```bash
cd /home/lebytek/htdocs/lebytek.com && php scripts/lebytek-api-health.php; echo "exit=$?"
tail -n 3 storage/logs/api-health.log
```
Expected: `exit=0`; el log NO contiene el token en claro.

- [ ] **Step 4: [AGENT] Pegar evidencia del crontab**

En `docs/integration/VPS_CHECKLIST.md`, sección `## Remediación — Crons (R2)`: marcar los dos `- [ ]` (health 5 min, expire 03:00) con fecha, y pegar la salida literal de `crontab -l` dentro del bloque de código existente. También marcar el ítem cron pendiente en la sección `## lebytek.com (VPS target)`.

- [ ] **Step 5: [AGENT] Commit**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs(gate): G1 crons installed (health 5m, expire demos 03:00) with crontab -l evidence

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: G2 — Smoke pasos 1–2 (provision demo + 2º correo)

**Files:**
- Modify: `docs/integration/VPS_CHECKLIST.md` — crear/rellenar la tabla canónica del smoke del gate (7 pasos).

**Interfaces:**
- Consumes: back-office desplegado (Task 2), api operativa (Task 1).
- Produces: un lead demo aprovisionado (`api_tenant_public_id` NOT NULL, `estado=demo_enviada`) y el 2º correo recibido con token por-tenant + base URL. Provee `TENANT_TOKEN` e `INSTANCE_PUBLIC_ID` para pasos 3–5.

- [ ] **Step 1: [AGENT] Insertar la tabla canónica del smoke en el checklist**

En `docs/integration/VPS_CHECKLIST.md`, añadir esta sección nueva (antes de `## api.lebytek.com`):
```markdown
## Gate pre-brainstorm — Smoke E2E (G2)

Ejecutar UNA sola vez en prod/staging VPS. Hard-gate: pasos 4, 5 y 6 deben ser ✅ antes de G3.

| # | Paso | Comando / acción | Pass | Fecha | Operador |
|---|------|------------------|------|-------|----------|
| 1 | Provision demo | CRUD lead `validada` → **Provisionar demo (api)** | [ ] | | |
| 2 | 2º correo | Token por-tenant + base URL + CTA waapi | [ ] | | |
| 3 | QR | `GET /instances/{id}/qr` o panel → escanear | [ ] | | |
| 4 | Authorized | `GET /instances/{id}` → `status=authorized` | [ ] | | |
| 5 | Mensaje | `smoke-send-test-message.php` → WhatsApp en móvil | [ ] | | |
| 6 | Panel waapi | Login token → dashboard → QR coherente | [ ] | | |
| 7 | Baja demo | Dar de baja → `demo_baja`, instancias gone | [ ] | | |
```

- [ ] **Step 2: [OPERATOR — back-office] Provisionar demo**

En el admin de lebytek.com: crear un lead de prueba en estado `validada` → clic **Provisionar demo (api)** en la fila.
Expected: la fila muestra `api_tenant_public_id` NOT NULL y `estado=demo_enviada`; sin `api_provision_error`.

- [ ] **Step 3: [OPERATOR — correo] Verificar el 2º correo**

Abrir el correo recibido por la dirección del lead.
Expected: contiene token Sanctum por-tenant, base URL `https://api.lebytek.com/api/v1`, y CTA a docs/portal. **NO** contiene ningún token de Green API. Guardar el token como `TENANT_TOKEN` y el `instancePublicId` (visible vía `GET /instances`) como `INSTANCE_PUBLIC_ID`.

- [ ] **Step 4: [AGENT] Marcar pasos 1–2**

Marcar filas 1 y 2 de la tabla smoke con `[x]`, fecha y operador reportados.

- [ ] **Step 5: [AGENT] Commit**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs(gate): G2 smoke steps 1-2 pass (provision demo + 2nd email, no green token)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 6: G2 — Smoke pasos 3–4 (QR → instancia authorized) · HARD-GATE 4

**Files:**
- Modify: `docs/integration/VPS_CHECKLIST.md` (tabla smoke, filas 3–4; y sección Remediación §2a).

**Interfaces:**
- Consumes: `TENANT_TOKEN`, `INSTANCE_PUBLIC_ID` (Task 5).
- Produces: instancia WhatsApp en `status=authorized` (hard-gate 4). Prerrequisito del envío (Task 7).

- [ ] **Step 1: [OPERATOR — teléfono] Obtener y escanear el QR**

```bash
curl -sf -H "Authorization: Bearer $TENANT_TOKEN" -H "Accept: application/json" \
  "https://api.lebytek.com/api/v1/instances/$INSTANCE_PUBLIC_ID/qr"
```
Escanear el QR devuelto con WhatsApp en el teléfono de prueba (vincular dispositivo).
Expected: respuesta 200 con el QR; el teléfono vincula el dispositivo.

- [ ] **Step 2: [OPERATOR — VPS] Confirmar authorized (hard-gate 4)**

```bash
curl -sf -H "Authorization: Bearer $TENANT_TOKEN" -H "Accept: application/json" \
  "https://api.lebytek.com/api/v1/instances/$INSTANCE_PUBLIC_ID"
```
Expected: JSON con `"status":"authorized"`. Si no llega a `authorized`, DETENER el gate y diagnosticar (no continuar a paso 5).

- [ ] **Step 3: [AGENT] Marcar pasos 3–4**

Marcar filas 3 y 4 de la tabla smoke con `[x]`, fecha y operador. En la sección `## Remediación — Fase 2a smoke mensaje`, marcar el paso 2 (`Cliente autoriza WhatsApp`).

- [ ] **Step 4: [AGENT] Commit**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs(gate): G2 smoke steps 3-4 pass (QR scanned, instance authorized) [hard-gate 4]

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 7: G2 — Smoke paso 5 (mensaje recibido en móvil) · HARD-GATE 5

**Files:**
- Modify: `docs/integration/VPS_CHECKLIST.md` (tabla smoke fila 5; sección Remediación §2a paso 3).

**Interfaces:**
- Consumes: instancia `authorized` (Task 6), `TENANT_TOKEN`, `INSTANCE_PUBLIC_ID`.
- Produces: evidencia de mensaje WhatsApp real entregado (hard-gate 5). Es el **desbloqueo del DNS cutover**.

- [ ] **Step 1: [OPERATOR — VPS lebytek] Enviar mensaje de prueba**

```bash
cd /home/lebytek/htdocs/lebytek.com
php scripts/smoke-send-test-message.php "$TENANT_TOKEN" "$INSTANCE_PUBLIC_ID" "521XXXXXXXXXX" "Test Lebytek API $(date +%H:%M)"
```
Expected: el script imprime `publicId` del mensaje y `status` inicial `queued`/`202`. Sin token Green en la salida.

- [ ] **Step 2: [OPERATOR — teléfono] Confirmar recepción física**

Revisar el teléfono `521XXXXXXXXXX`.
Expected: el mensaje "Test Lebytek API HH:MM" llega a WhatsApp. **Esta es la evidencia crítica del gate.**

- [ ] **Step 3: [OPERATOR — VPS] Confirmar estado `sent`**

```bash
curl -sf -H "Authorization: Bearer $TENANT_TOKEN" -H "Accept: application/json" \
  "https://api.lebytek.com/api/v1/messages/<publicId-del-paso-1>"
```
Expected: `"status":"sent"` (o `queued`→`sent` tras el job Horizon); nunca aparece el token Green.

- [ ] **Step 4: [AGENT] Marcar paso 5**

Marcar fila 5 de la tabla smoke con `[x]`, fecha y operador. En `## Remediación — Fase 2a smoke mensaje`, marcar el paso 3 (`POST /messages → WhatsApp recibido en móvil`) con fecha.

- [ ] **Step 5: [AGENT] Commit**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs(gate): G2 smoke step 5 pass (WhatsApp delivered to mobile, status=sent) [hard-gate 5]

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 8: G2 — Smoke paso 6 (panel waapi con token demo) · HARD-GATE 6

**Files:**
- Modify: `docs/integration/VPS_CHECKLIST.md` (tabla smoke fila 6; sección waapi Fase 4 "Login con token demo").

**Interfaces:**
- Consumes: portal waapi desplegado (Task 3), `TENANT_TOKEN`.
- Produces: verificación de que el cliente ve su instancia/estado en modo lectura (hard-gate 6).

- [ ] **Step 1: [OPERATOR — navegador] Login en el portal con el token del correo**

Ir a `https://waapi.lebytek.com/portal/acceso` e ingresar el `TENANT_TOKEN` del 2º correo.
Expected: el dashboard carga y muestra la instancia del tenant con su estado (`authorized`). El QR mostrado (si aplica) es coherente con el de api. **No** se expone ningún token Green.

- [ ] **Step 2: [AGENT] Marcar paso 6**

Marcar fila 6 de la tabla smoke con `[x]`, fecha y operador. En `## waapi.lebytek.com (panel cliente Fase 4)`, marcar "Login con token demo del correo → dashboard muestra instancia".

- [ ] **Step 3: [AGENT] Commit**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs(gate): G2 smoke step 6 pass (waapi portal login shows tenant instance) [hard-gate 6]

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 9: G2 — Smoke paso 7 (baja demo E2E)

**Files:**
- Modify: `docs/integration/VPS_CHECKLIST.md` (tabla smoke fila 7; sección Remediación §2a paso 4).

**Interfaces:**
- Consumes: lead demo aprovisionado (Task 5), instancias creadas.
- Produces: cierre del ciclo lifecycle (`demo_baja`, instancias eliminadas en api).

- [ ] **Step 1: [OPERATOR — back-office] Dar de baja el demo**

En el admin: acción **Dar de baja demo** sobre el lead de prueba.
Expected: `estado=demo_baja`.

- [ ] **Step 2: [OPERATOR — VPS] Confirmar instancias eliminadas en api**

```bash
curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $TENANT_TOKEN" \
  -H "Accept: application/json" \
  "https://api.lebytek.com/api/v1/instances/$INSTANCE_PUBLIC_ID"
```
Expected: `404` (la instancia ya no existe / no accesible).

- [ ] **Step 3: [AGENT] Marcar paso 7**

Marcar fila 7 de la tabla smoke con `[x]`, fecha y operador. En `## Remediación — Fase 2a smoke mensaje`, marcar el paso 4 (`Dar de baja demo → instancias eliminadas, demo_baja`).

- [ ] **Step 4: [AGENT] Commit**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs(gate): G2 smoke step 7 pass (demo deprovisioned, instances gone in api)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 10: G4 — Cierre documental (1 PR api)

**Files:**
- Modify: `docs/integration/waapi-api-contract.md` (nota 501 en `/credentials/green-api`)
- Modify: `docs/superpowers/specs/2026-07-02-integration-phase4-5-design.md` (banner superseded en §0)
- Modify: `docs/superpowers/specs/2026-07-02-integration-roadmap-remediation-design.md` (checkboxes §6.1–6.2, y §6.3/§6.4 con fecha)
- Modify: `docs/integration/README.md` (tabla roadmap + banner)
- Modify: `docs/integration/VPS_CHECKLIST.md` (deduplicar sección waapi legacy)

**Interfaces:**
- Consumes: resultados del smoke G2 (Tasks 5–9) para marcar estados honestos.
- Produces: docs alineadas; ningún doc dice "Fase 2/3 completa" sin distinguir código vs ops.

- [ ] **Step 1: Nota 501 en el contrato**

En `docs/integration/waapi-api-contract.md`, justo después de la tabla de "Endpoints — Fase 2 (planned, no implementados)" (la que incluye `PUT /credentials/green-api`), añadir:
```markdown
> **Nota `/credentials/green-api` (501):** la ruta `PUT /credentials/green-api` ya está **cableada como stub** (`CredentialsController::updateGreenApi`, permiso `credenciales.gestionar`) y responde **`501 Not Implemented`**. BYO credentials sigue fuera del MVP; el endpoint existe solo para reservar el contrato. No consumir hasta que aparezca funcional en OpenAPI.
```

- [ ] **Step 2: Banner superseded en el spec Fase 4/5**

En `docs/superpowers/specs/2026-07-02-integration-phase4-5-design.md`, insertar tras la primera línea `---` (después del frontmatter, antes de `## 0. Auditoría`):
```markdown
> **⚠️ Superseded parcialmente (2026-07-03):** la tabla de auditoría de este §0 quedó desactualizada (marcaba `/messages` como pendiente). El estado real y el cierre operativo se rigen por [2026-07-02-integration-pre-brainstorm-gate-design.md](2026-07-02-integration-pre-brainstorm-gate-design.md) y su plan `../plans/2026-07-03-integration-pre-brainstorm-gate.md`. Conservar este spec solo como diseño de panel/madurez, no como estado.
```

- [ ] **Step 3: Marcar checkboxes de remediación §6.1 y §6.2**

En `docs/superpowers/specs/2026-07-02-integration-roadmap-remediation-design.md`, cambiar los `- [ ]` de §6.1 (4 ítems) y §6.2 (4 ítems) a `- [x]` (código listo + smoke verificado). En §6.3 (tabla), añadir una columna/nota con la fecha del smoke. En §6.4, marcar `Crons health + expire activos` con `[x]`; marcar `lebytek.com resuelve VPS` y `Framework en main desplegado` **solo si** el DNS cutover se completó en Task 12 (si se pospone, dejar `[ ]` con nota).

- [ ] **Step 4: Actualizar tabla roadmap del README**

En `docs/integration/README.md`, tabla `## Roadmap real (integración)`, aplicar (con la fecha real del smoke):
```markdown
| 2a | Vertical api `/messages` | ✅ código · ✅ smoke VPS (2026-07-0X) |
| 3 | Go-live DNS/docs/main | ✅ go-live (2026-07-0X) |
| 4 | Portal waapi (lectura) | ✅ código · ✅ VPS (2026-07-0X) |
```
Y reemplazar la línea 34 (`> **Fase 4/5 en progreso:** ...`) por:
```markdown
> **Gate pre-brainstorm cerrado (2026-07-0X):** smoke E2E verde (mensaje en móvil), portal waapi live, crons activos. Ver `VPS_CHECKLIST.md` § GO/NO-GO.
```
Si el DNS se pospone (NO-GO parcial), poner en fila 3 `⏳ DNS pospuesto (razón)` en lugar de `✅ go-live`.

- [ ] **Step 5: Deduplicar la sección waapi legacy del checklist**

En `docs/integration/VPS_CHECKLIST.md`, reemplazar todo el bloque `## waapi.lebytek.com (legacy — reemplazado por sección anterior)` y sus subsecciones (`### Entorno`, `### Integración (diferido...)`) por una sola línea:
```markdown
## waapi.lebytek.com (legacy) — DEPRECADO

Reemplazado por la sección `## waapi.lebytek.com (panel cliente Fase 4)`. No usar.
```

- [ ] **Step 6: Commit + PR**

```bash
git add docs/integration/waapi-api-contract.md \
        docs/superpowers/specs/2026-07-02-integration-phase4-5-design.md \
        docs/superpowers/specs/2026-07-02-integration-roadmap-remediation-design.md \
        docs/integration/README.md \
        docs/integration/VPS_CHECKLIST.md
git commit -m "docs(gate): G4 closure — 501 note, superseded banner, roadmap honest, dedupe legacy

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```
Expected: 1 commit de cierre documental. Abrir PR contra `main` de `WhatsApiLebytek` (docs only). NO tocar `main` de Framework.

---

### Task 11: G3 — Sync + deploy docs.lebytek.com (solo si G2 verde)

**Files:**
- Modify (evidencia): `docs/integration/VPS_CHECKLIST.md` (tabla smoke fila 5 de Remediación: `docs.lebytek.com muestra /messages`).

**Interfaces:**
- Consumes: hard-gate 4/5/6 en verde (Tasks 6–8). Si alguno falla, NO ejecutar esta tarea.
- Produces: docs públicas live y sincronizadas con el contrato (incluye `/messages`).

- [ ] **Step 1: [AGENT] Verificar precondición hard-gate**

Confirmar en `docs/integration/VPS_CHECKLIST.md` que las filas 4, 5 y 6 de la tabla smoke están `[x]`. Si alguna está `[ ]`, DETENER y saltar a Task 13 (declarar NO-GO).

- [ ] **Step 2: [OPERATOR — repo docs] Sincronizar y desplegar**

```bash
cd <repo-docs.lebytek.com>
node scripts/sync-docs.mjs
# desplegar el output estático al vhost nginx de docs.lebytek.com (según CloudPanel)
```
Expected: sync sin errores; el mirror incluye la sección `Endpoints — Fase 2a (implementados)`.

- [ ] **Step 3: [OPERATOR] Smoke de docs live**

```bash
curl -sf https://docs.lebytek.com/ | grep -c "messages"
```
Expected: ≥ 1 coincidencia (el contrato con `/messages` está publicado).

- [ ] **Step 4: [AGENT] Capturar evidencia + commit**

En `## Remediación — Fase 2a smoke mensaje`, marcar paso 5 (`docs.lebytek.com muestra /messages implementado`) con fecha.
```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs(gate): G3 docs.lebytek.com live and synced (/messages published)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 12: G3 — DNS cutover lebytek.com + smoke post-cutover (solo si G2 verde)

**Files:**
- Modify (evidencia): `docs/integration/VPS_CHECKLIST.md` (sección `## Remediación — Go-live Fase 3` y `### DNS`).

**Interfaces:**
- Consumes: smoke paso 5 verde (Task 7) — **precondición absoluta**; back-office VPS operativo (Task 2).
- Produces: `lebytek.com` resolviendo al VPS; smoke post-cutover verde. **No** merge a main.

- [ ] **Step 1: [OPERATOR] Bajar TTL del DNS (24h antes del cutover)**

En el proveedor DNS, reducir el TTL del registro de `lebytek.com` a 300s **al menos 24h antes** del cambio. Expected: TTL bajo propagado antes de continuar.

- [ ] **Step 2: [AGENT] Verificar precondición mensaje**

Confirmar que la fila 5 de la tabla smoke está `[x]`. Si está `[ ]`, DETENER: prohibido cutover sin mensaje verde (Global Constraint).

- [ ] **Step 3: [OPERATOR] Ejecutar el cutover DNS**

Apuntar el registro A/CNAME de `lebytek.com` del hosting FTP legacy al VPS (IP del vhost lebytek). Expected: propagación; `dig +short lebytek.com` devuelve la IP del VPS.

- [ ] **Step 4: [OPERATOR] Smoke post-cutover**

```bash
curl -sfI https://lebytek.com/ | head -1
curl -sfI https://lebytek.com/admin/login | head -1
```
Y repetir el paso 1 de provisioning (crear lead `validada` → **Provisionar demo (api)**) para confirmar el back-office sobre el dominio real.
Expected: landing 200, admin 200, provisioning OK sobre `lebytek.com`.

- [ ] **Step 5: [AGENT] Capturar evidencia + commit**

En `docs/integration/VPS_CHECKLIST.md`: marcar en `## Remediación — Go-live Fase 3` los ítems de DNS y sync docs con fecha. Marcar `### DNS` de la sección lebytek.com. **Dejar explícito** que Framework NO se mergeó a main (nota: "deploy desde feature branch; merge a main pendiente de orden explícita").
```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs(gate): G3 DNS cutover lebytek.com -> VPS, post-cutover smoke green (no main merge)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 13: G5 — Declaración GO / NO-GO

**Files:**
- Modify: `docs/integration/VPS_CHECKLIST.md` (añadir bloque GO/NO-GO al inicio o cerca de la tabla smoke).

**Interfaces:**
- Consumes: resultados de todas las tareas anteriores (G0–G4, G3 si aplica).
- Produces: decisión formal que desbloquea (o no) el siguiente brainstorm.

- [ ] **Step 1: [AGENT] Insertar el bloque de decisión**

En `docs/integration/VPS_CHECKLIST.md`, añadir:
```markdown
## Gate pre-brainstorm — GO/NO-GO (fecha: 2026-07-0X)

- [ ] G0 deploy VPS (api ≥ dcc46d0, Framework ≥ 0868af2, waapi portal)
- [ ] G1 crons (crontab -l capturado)
- [ ] G2 smoke E2E (pasos 4–6 hard-gate ✅)
- [ ] G3 go-live (si aplica este sprint; DNS solo tras paso 5)
- [ ] G4 docs (roadmap honesto, 501 note, dedupe)

**Decisión:** GO / NO-GO para siguiente brainstorm
**Operador:** ______
```

- [ ] **Step 2: [AGENT] Marcar según evidencia real**

Marcar cada `- [ ]` del bloque solo si su tarea tiene evidencia (fecha) en el checklist. Escribir `GO` únicamente si G0, G1, G2 (pasos 4–6) y G4 están completos; G3 puede quedar pospuesto con razón documentada sin bloquear GO (según criterio del spec §4). Si falta evidencia de mensaje en móvil (paso 5), la decisión es **NO-GO**.

- [ ] **Step 3: [AGENT] Verificar criterios de aceptación del spec**

Revisar contra `2026-07-02-integration-pre-brainstorm-gate-design.md` §4 que se cumplen: migraciones `int_mensajes` aplicadas + Horizon RUNNING; mensaje WhatsApp recibido en móvil; portal waapi accesible con token demo; crons confirmados; docs.lebytek.com responde con `/messages` (si G3); ningún doc dice "Fase 2/3 completa" sin distinguir código vs ops; decisión DNS documentada. Corregir cualquier `[x]` sin evidencia.

- [ ] **Step 4: [AGENT] Commit final del gate**

```bash
git add docs/integration/VPS_CHECKLIST.md
git commit -m "docs(gate): G5 GO/NO-GO declaration for pre-brainstorm gate

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```
Expected: gate cerrado. Si GO, el siguiente brainstorm (campañas/madurez) queda desbloqueado.

---

## Self-Review

**Spec coverage (gate spec §3 G0–G5 + §1.4 deuda documental):**
- G0 deploy (api/lebytek/waapi) → Tasks 1, 2, 3 ✅
- G1 crons → Task 4 ✅
- G2 smoke 7 pasos (hard-gate 4/5/6) → Tasks 5, 6, 7, 8, 9 ✅
- G3 go-live (docs sync + DNS + post-cutover, condicional) → Tasks 11, 12 ✅
- G4 docs (501 note, superseded banner, remediación §6, README roadmap, dedupe legacy) → Task 10 ✅
- G5 GO/NO-GO → Task 13 ✅
- §1.4 deuda documental: 501 note (T10 s1), phase4-5 banner (T10 s2), remediación checkboxes (T10 s3), docs.lebytek.com mirror (T11), dedupe waapi legacy en checklist (T10 s5) ✅
- §4 criterios de aceptación → verificados en Task 13 s3 ✅

**Placeholder scan:** sin TODO/TBD; cada edición de doc muestra el texto literal a insertar; cada acción de operador tiene comando exacto + salida esperada. Los `XXXXXXXXXX`/`2026-07-0X`/`<publicId...>` son variables de runtime del operador (número de teléfono, fecha real, id devuelto), no placeholders de plan.

**Type/nombres consistentes:** `TENANT_TOKEN` e `INSTANCE_PUBLIC_ID` definidos en Task 5 s3 y reutilizados idénticos en Tasks 6–9, 12. Rutas VPS y umbrales de commit (`dcc46d0`, `0868af2`) idénticos a Global Constraints. Nombres de sección del checklist citados literalmente del archivo actual.

**Nota de honestidad del plan:** muchos pasos requieren un operador humano con SSH al VPS y un teléfono físico (escanear QR, recibir WhatsApp, DNS) — están marcados `[OPERATOR]`. El agente ejecuta las ediciones de repo y captura la evidencia que el operador reporta. Esto es inherente a un gate de ops; no es automatizable de punta a punta.
