# Checklist VPS — api + lebytek.com + waapi

Ejecutar **después** de configurar acceso SSH (ver `tools/setup_vps_ssh.py` → alias `lebytek-vps`).

---

## api.lebytek.com

Ruta: `/home/lebytek-api/htdocs/api.lebytek.com`  
Usuario CloudPanel: `lebytek-api`

### Acceso y código

- [ ] `ssh lebytek-vps` funciona sin contraseña
- [ ] `git remote -v` apunta a `https://github.com/Parzival2103/WhatsApiLebytek.git`
- [ ] `git pull origin main` sin conflictos
- [ ] `composer install --no-dev` OK
- [ ] `npm ci && npm run build` OK

### Entorno

- [ ] `.env` existe (no en git): `APP_URL=https://api.lebytek.com`
- [ ] Redis: `REDIS_HOST=127.0.0.1`, `QUEUE_CONNECTION=redis`
- [ ] BD CloudPanel: `lebytekapi`
- [ ] R2/uploads: `UPLOADS_DISK=s3`, credenciales AWS/R2
- [ ] `WEBHOOK_SECRET` generado
- [ ] `WAAPI_SERVICE_EMAIL` configurado (alias futuro documentado: `PLATFORM_SERVICE_*` — renombrado P2)

### Migraciones y token plataforma

- [ ] `php artisan migrate --force`
- [ ] `php artisan integration:issue-waapi-token --revoke` → token copiado a **lebytek.com** `.env` (`LEBYTEK_API_TOKEN` — consumidor primario)
- [ ] waapi.lebytek.com mantiene copia legacy del token para fase panel (no orquestador)

### Servicios

- [ ] `supervisorctl status lebytek-api-horizon` → RUNNING
- [ ] `php artisan horizon:status` → running

### Smoke tests

```bash
curl -sf https://api.lebytek.com/up
curl -sf https://api.lebytek.com/manifest.webmanifest
curl -sfI https://api.lebytek.com/admin/login | head -1
curl -sf -H "Authorization: Bearer <token>" https://api.lebytek.com/api/v1/health
```

- [ ] `/up` → 200
- [ ] `/admin/login` accesible
- [ ] `/api/v1/health` con token → 200, `checks.database.ok` y `checks.redis.ok`
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

- [ ] Clone/pull Lebytek_Framework feature branch
- [ ] `composer install --no-dev`
- [ ] Document root → `public/`
- [ ] `.env`: DB, MAIL_*, LEBYTEK_API_URL, LEBYTEK_API_TOKEN

### BD

- [ ] Installer or `php scripts/migrate.php` + seed
- [ ] Marketing module + dom_mkt_leads

### Smoke

- [ ] Landing `/` loads
- [ ] `/admin/login` loads
- [ ] `GET /api/v1/health` from server using LEBYTEK_API_TOKEN → 200

### DNS

- [ ] **Do not** point lebytek.com DNS here until E2E green (FTP legacy still live)

---

## waapi.lebytek.com (congelado — panel fase final)

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
