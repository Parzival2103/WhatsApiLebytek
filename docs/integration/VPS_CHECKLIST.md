# Checklist VPS — api + lebytek.com + waapi

Ejecutar **después** de configurar acceso SSH (ver `tools/setup_vps_ssh.py` → alias `lebytek-vps`).

---

## E2E Fase 0 — verificación (2026-07-01)

Criterios de aceptación del spec `2026-07-01-integration-e2e-phase0-1-design.md` §4.5:

- [x] `GREEN_API_PARTNER_TOKEN` configurado y no vacío en api VPS (2026-07-01)
- [x] `LEBYTEK_API_TOKEN` + `MAIL_*` smtp configurados en lebytek VPS (2026-07-01)
- [x] Deploy lebytek ≥ `c2d51cd` — `health_rc=0` en `vps-deploy-lebytek-com.sh` (2026-07-01)
- [x] `php scripts/lebytek-api-health.php` → exit 0 (2026-07-01)
- [x] Smoke E2E provisioning verde — botón **Provisionar demo (api)** en CRUD leads (2026-07-01)
- [x] Cron health cada 5 min — crontab confirmado (2026-07-04; ver § Remediación Crons)
- [x] `VPS_CHECKLIST.md` actualizado con resultados (2026-07-01)

Flujo E2E manual: crear lead `validada` → clic **Provisionar demo (api)** → verificar `api_tenant_public_id`, `estado=demo_enviada`, correo con token + base URL (sin token Green).

---

## Remediación — Fase 2a smoke mensaje (bloqueado hasta R1)

**No marcar `[x]` hasta smoke manual documentado con fecha.**

| Paso | Check | Estado |
|------|-------|--------|
| 1 | Lead `validada` → Provisionar demo → `demo_enviada` + correo | [x] 2026-07-01 |
| 2 | Cliente autoriza WhatsApp (QR vía api) | [ ] |
| 3 | `POST /messages` con token del correo → WhatsApp recibido en móvil | [ ] |

Script smoke (Framework, tras deploy api):

```bash
php scripts/smoke-send-test-message.php "$TENANT_TOKEN" "$INSTANCE_PUBLIC_ID" "521XXXXXXXXXX" "Test Lebytek API"
```

| 4 | Dar de baja demo → instancias eliminadas, `demo_baja` | [ ] |
| 5 | `docs.lebytek.com` muestra `/messages` implementado | [ ] |

## Remediación — Crons (R2)

Capturar salida en este checklist al confirmar:

```bash
crontab -l -u lebytek
```

Salida capturada (2026-07-04):

```
*/5 * * * * cd /home/lebytek/htdocs/lebytek.com && php scripts/lebytek-api-health.php >> storage/logs/api-health.log 2>&1
0 3 * * * cd /home/lebytek/htdocs/lebytek.com && php scripts/expire-api-demos.php 30 >> storage/logs/expire-api-demos.log 2>&1
*/5 * * * * cd /home/lebytek/htdocs/lebytek.com && php scripts/lebytek-api-health.php >> storage/logs/api-health.log 2>&1
0 3 * * * cd /home/lebytek/htdocs/lebytek.com && php scripts/expire-api-demos.php 30 >> storage/logs/expire-demos.log 2>&1
```

> ⚠️ **Duplicados:** el crontab tiene cada job ×2 (logs distintos en expire). Dejar solo un par antes de cerrar G5.

- [x] Cron health cada 5 min (2026-07-04, manual `exit=0`, log sin token)
- [x] Cron expire demos diario 03:00 (2026-07-04)

## Remediación — Go-live Fase 3 (bloqueado hasta R1 smoke verde)

- [ ] **Do not** DNS cutover until paso 3 smoke mensaje verde
- [ ] Framework `feature/backoffice-api-integration` merged → `main` + tag semver
- [ ] `node scripts/sync-docs.mjs` + deploy `docs.lebytek.com`
- [ ] Rollback runbook probado (ver § Rollback rápido)

## Phase 4/5 baseline (2026-07-02)

Auditoría local pre-implementación panel waapi (spec `2026-07-02-integration-phase4-5-design.md` §0):

| Ítem | Estado | Evidencia |
|------|--------|-----------|
| `/messages` en api | ✅ código | `routes/api.php` + tests Pest verdes local |
| Token demo `mensajes.*` | ✅ | `LeadApiProvisioningService` Framework |
| waapi deploy mode | **Enfoque B** | Mismo tree Framework; `WAAPI_PORTAL_ENABLED=true` en vhost waapi |
| `/messages` smoke VPS móvil | ⏳ | Remediación §2a paso 3 pendiente |
| Cron health VPS | ✅ | crontab confirmado 2026-07-04 (dedupe pendiente) |
| Panel waapi código | ✅ | `routes/waapi_portal.php` + `WaapiPortalController` |
| waapi VPS live | ✅ | `/portal/acceso` GET 200 (2026-07-04) |
| `MKT_EMAIL_DASHBOARD_URL` | ✅ código | `.env.example` + plantilla correo |
| DNS cutover lebytek.com | ⏳ | DNS público aún FTP legacy; vhost VPS verificado localmente (2026-07-04) |

---

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

---

## api.lebytek.com

### A-001 deploy evidence (2026-07-19) — Issue #17

- HEAD: `9e62475` (main = origin/main)
- `git pull` + `composer install --no-dev` + `npm ci` + `npm run build` + caches + `scribe:generate`: OK
- `php artisan migrate --force`: Nothing to migrate
- Horizon: restarted (`lebytek-api-horizon` RUNNING); `supervisor-webhooks` / queue `webhooks` present in `config/horizon.php`
- cron `* * * * * … php artisan schedule:run` installed for user `lebytek-api` (was missing; deploy-api.sh every 3 min retained)
- `php artisan schedule:list`: `webhooks:check-unprocessed` every 5 min
- smoke: `https://api.lebytek.com/up` → 200
- Commercial activate-plan E2E: deferred to human (no platform token in agent session)


Ruta: `/home/lebytek-api/htdocs/api.lebytek.com`  
Usuario CloudPanel: `lebytek-api`

### Acceso y código

- [ ] `ssh lebytek-vps` funciona sin contraseña
- [ ] `git remote -v` apunta a `https://github.com/Parzival2103/WhatsApiLebytek.git`
- [x] `git pull origin main` sin conflictos (2026-07-19, HEAD `9e62475` = origin/main; prev 2026-07-04 a454121)
- [x] `composer install --no-dev` OK (2026-07-19; prev 2026-07-04)
- [x] `npm ci && npm run build` OK (2026-07-19)

### Entorno

- [ ] `.env` existe (no en git): `APP_URL=https://api.lebytek.com`
- [x] `GREEN_API_PARTNER_TOKEN` configurado (2026-07-01)
- [ ] Redis: `REDIS_HOST=127.0.0.1`, `QUEUE_CONNECTION=redis`
- [ ] BD CloudPanel: `lebytekapi`
- [ ] R2/uploads: `UPLOADS_DISK=s3`, credenciales AWS/R2
- [ ] `WEBHOOK_SECRET` generado
- [ ] `WAAPI_SERVICE_EMAIL` configurado (alias futuro documentado: `PLATFORM_SERVICE_*` — renombrado P2)

### Migraciones y token plataforma

- [x] `php artisan migrate --force` (2026-07-19, Nothing to migrate — meta + int_mensajes ya aplicadas; prev 2026-07-04)
- [ ] `php artisan integration:issue-waapi-token --revoke` → token copiado a **lebytek.com** `.env` (`LEBYTEK_API_TOKEN` — consumidor primario)
- [ ] waapi.lebytek.com mantiene copia legacy del token para fase panel (no orquestador)

### Servicios

- [x] `supervisorctl status lebytek-api-horizon` — RUNNING (2026-07-19; prev 2026-07-04)
- [x] `php artisan horizon:status` — running (2026-07-19; `supervisor-webhooks` / queue `webhooks` en config/horizon.php; prev 2026-07-04)

### Smoke tests

```bash
curl -sf https://api.lebytek.com/up
curl -sf https://api.lebytek.com/manifest.webmanifest
curl -sfI https://api.lebytek.com/admin/login | head -1
curl -sf -H "Authorization: Bearer <token>" https://api.lebytek.com/api/v1/health
```

- [x] `/up` — 200 (2026-07-19; prev 2026-07-04)
- [ ] `/admin/login` accesible
- [x] `/api/v1/health` con token → 200, `checks.database.ok` y `checks.redis.ok` (2026-07-04)
- [ ] Horizon accesible para email en `HORIZON_ALLOWED_EMAILS`

### Provisioning E2E (desde back-office o curl)

```bash
curl -X POST https://api.lebytek.com/api/v1/tenants \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"name":"Test Lead","slug":"test-lead","externalRef":"lebytek_lead_test"}'
```

- [ ] Respuesta 201 con `publicId`

---

## lebytek.com (VPS target)

Ruta: `/home/lebytek/htdocs/lebytek.com`  
Usuario CloudPanel: `lebytek`  
Branch: `feature/backoffice-api-integration` (until merge)

### Código

- [x] Clone/pull Lebytek_Framework feature branch (2026-07-04, `feature/backoffice-api-integration`, HEAD baca01f ≥ 0868af2)
- [x] `composer install --no-dev` (2026-07-04)
- [ ] Document root → `public/`
- [x] `.env`: DB, MAIL_*, LEBYTEK_API_URL, LEBYTEK_API_TOKEN (2026-07-01; health exit=0 re-verificado 2026-07-04)
- [x] `LEBYTEK_API_TOKEN` + `MAIL_*` smtp configurados (2026-07-01)
- [x] Deploy ≥ `0868af2` — HEAD `baca01f`, `lebytek-api-health.php` exit=0 (2026-07-04)

### BD

- [x] Installer or `php scripts/migrate.php` + seed (2026-07-04, schema aplicado)
- [ ] Marketing module + dom_mkt_leads

### Smoke

- [x] Landing `/` loads (2026-07-04, vhost VPS `--resolve` → 200; DNS público aún FTP legacy → 405)
- [x] `/login` loads (2026-07-04, vhost VPS → 200; ruta admin es `/login`, no `/admin/login`)
- [x] `GET /api/v1/health` from server using LEBYTEK_API_TOKEN → 200 (2026-07-04, `lebytek-api-health.php` exit=0)

### E2E provisioning (back-office)

1. Admin → CRUD Leads → lead de prueba en estado `validada`
2. Clic **Provisionar demo (api)** en la fila
3. Verificar: `api_tenant_public_id` NOT NULL, `estado=demo_enviada`, correo recibido

- [x] Smoke E2E provisioning verde (2026-07-01)
- [x] Cron health cada 5 min + expire 03:00 — crontab confirmado (2026-07-04)

### DNS

- [ ] **Do not** point lebytek.com DNS here until E2E green (FTP legacy still live)

---

## waapi.lebytek.com (panel cliente Fase 4)

Ejecutar en vhost waapi (mismo codebase Framework; ver `docs/guides/portal-cliente-waapi.md`).

### Entorno

- [x] `WAAPI_PORTAL_ENABLED=true` (2026-07-04, vhost `/home/lebytek-waapi/htdocs/waapi.lebytek.com`)
- [x] `LEBYTEK_API_URL=https://api.lebytek.com/api/v1` (2026-07-04)
- [x] `MKT_EMAIL_DASHBOARD_URL=https://waapi.lebytek.com/portal/acceso` (en lebytek.com prod, 2026-07-04)
- [ ] **No** requiere `LEBYTEK_API_TOKEN` plataforma — ⚠️ legacy presente en waapi `.env`; portal usa token por-tenant (revisar eliminar)

### Smoke panel

- [x] `curl /portal/acceso` → 200 GET (2026-07-04; `curl -I` devuelve 405 — usar GET)
- [ ] Login con token demo del correo → dashboard muestra instancia
- [ ] QR funcional para instancia `waiting_qr`
- [x] `grep -r "green-api" app/Presentation/Controllers/Publico/WaapiPortalController.php` → 0 hits (2026-07-04)

---

## waapi.lebytek.com (legacy — reemplazado por sección anterior)

Ejecutar en el VPS/sitio de waapi (ruta según CloudPanel del proyecto skeleton).

### Entorno

- [ ] `LEBYTEK_API_URL=https://api.lebytek.com/api/v1`
- [ ] `LEBYTEK_API_TOKEN` = token emitido en api (copia legacy)
- [ ] Token **no** commiteado en git

### Integración (diferido — panel fase posterior)

- [ ] Migración `dom_mkt_leads` en back-office lebytek.com — **diferido**
- [ ] Registro de prueba crea tenant en api — **diferido**
- [ ] `api_tenant_public_id` persistido — **diferido**
- [ ] Health check waapi → api OK — **diferido**
- [ ] `grep -r "green-api" app/` sin llamadas directas (salvo docs) — **diferido**

---

## lebytek.com (marketing / FTP legacy)

- [ ] CTA puede apuntar a landing propia o waapi según fase
- [ ] Integración api vía back-office en VPS target (no en monolito FTP México)

---

## Rollback rápido

```bash
# api — volver al commit anterior
cd /home/lebytek-api/htdocs/api.lebytek.com
sudo -u lebytek-api git checkout HEAD~1
sudo -u lebytek-api composer install --no-dev
sudo -u lebytek-api php artisan migrate --force
supervisorctl restart lebytek-api-horizon:*
```
