# Checklist VPS — api + waapi

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
- [ ] `WAAPI_SERVICE_EMAIL` configurado

### Migraciones y cuenta waapi

- [ ] `php artisan migrate --force`
- [ ] `php artisan integration:issue-waapi-token --revoke` → token copiado a waapi

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

### Provisioning E2E (desde waapi o curl)

```bash
curl -X POST https://api.lebytek.com/api/v1/tenants \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"name":"Test Org","slug":"test-org","externalRef":"waapi_org_test"}'
```

- [ ] Respuesta 201 con `publicId`

---

## waapi.lebytek.com

Ejecutar en el VPS/sitio de waapi (ruta según CloudPanel del proyecto skeleton).

### Entorno

- [ ] `LEBYTEK_API_URL=https://api.lebytek.com/api/v1`
- [ ] `LEBYTEK_API_TOKEN` = token emitido en api
- [ ] Token **no** commiteado en git

### Integración (tras implementar en repo waapi)

- [ ] Migración `organizations` aplicada
- [ ] Registro de prueba crea tenant en api
- [ ] `api_tenant_public_id` persistido
- [ ] Health check waapi → api OK
- [ ] `grep -r "green-api" app/` sin llamadas directas (salvo docs)

---

## lebytek.com (marketing)

- [ ] CTA "Productos" / "Acceder" apunta a `waapi.lebytek.com`
- [ ] Sin lógica WhatsApp ni tokens api

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
