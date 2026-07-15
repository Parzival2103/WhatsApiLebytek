# Deploy — api.lebytek.com

## Ubicación en el VPS

```
/home/lebytek-api/htdocs/api.lebytek.com/          ← raíz del repo (artisan, app/, config/)
/home/lebytek-api/htdocs/api.lebytek.com/public/   ← document root nginx
```

CloudPanel site user: **lebytek-api**

Supervisor program: **lebytek-api-horizon** (`php artisan horizon`)

---

## Primera vez (clonar repo)

```bash
cd /home/lebytek-api/htdocs
mv api.lebytek.com api.lebytek.com.bak-$(date +%F) 2>/dev/null || true
git clone https://github.com/Parzival2103/WhatsApiLebytek.git api.lebytek.com
chown -R lebytek-api:lebytek-api api.lebytek.com
cd api.lebytek.com
sudo -u lebytek-api cp .env.example .env
# Editar .env (ver sección Variables producción)
sudo -u lebytek-api composer install --no-dev --optimize-autoloader
sudo -u lebytek-api php artisan key:generate
sudo -u lebytek-api npm ci
sudo -u lebytek-api npm run build
sudo -u lebytek-api php artisan migrate --force
sudo -u lebytek-api php artisan db:seed --class=ProductionSeeder --force
sudo -u lebytek-api php artisan config:cache
sudo -u lebytek-api php artisan route:cache
sudo -u lebytek-api php artisan view:cache
sudo -u lebytek-api php artisan scribe:generate
supervisorctl restart lebytek-api-horizon:*
```

---

## Actualización (deploy rutinario)

```bash
cd /home/lebytek-api/htdocs/api.lebytek.com
sudo -u lebytek-api git pull origin main
sudo -u lebytek-api composer install --no-dev --optimize-autoloader
sudo -u lebytek-api npm ci
sudo -u lebytek-api npm run build
sudo -u lebytek-api php artisan migrate --force
sudo -u lebytek-api php artisan config:cache
sudo -u lebytek-api php artisan route:cache
sudo -u lebytek-api php artisan view:cache
sudo -u lebytek-api php artisan scribe:generate
supervisorctl restart lebytek-api-horizon:*
```

---

## Supervisor (Horizon)

Ejemplo `/etc/supervisor/conf.d/lebytek-api-horizon.conf`:

```ini
[program:lebytek-api-horizon]
process_name=%(program_name)s
command=php /home/lebytek-api/htdocs/api.lebytek.com/artisan horizon
autostart=true
autorestart=true
user=lebytek-api
redirect_stderr=true
stdout_logfile=/home/lebytek-api/htdocs/api.lebytek.com/storage/logs/horizon.log
stopwaitsecs=3600
```

Horizon gestiona las colas `default`, `transactional` y `campaigns` (Redis).

---

## Variables `.env` producción

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.lebytek.com

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1

DB_DATABASE=lebytekapi
DB_USERNAME=lebytekapi
# DB_PASSWORD → CloudPanel → Databases

# Cloudflare R2 (uploads branding)
UPLOADS_DISK=s3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=auto
AWS_BUCKET=
AWS_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true

# Admin inicial (ProductionSeeder — solo primera vez o reset controlado)
ADMIN_INITIAL_EMAIL=admin@tu-dominio.com
ADMIN_INITIAL_NAME=Administrador
ADMIN_INITIAL_PASSWORD=<generar-password-seguro>

# Horizon
HORIZON_ALLOWED_EMAILS=admin@tu-dominio.com
HORIZON_MAIL=ops@tu-dominio.com
# HORIZON_SLACK_WEBHOOK=
# HORIZON_SLACK_CHANNEL=#alerts

# Required for HMAC clients AND Green webhookUrlToken (Bearer on incoming webhooks)
WEBHOOK_SECRET=<generar-secreto-hmac>

GREEN_API_BASE_URL=https://api.green-api.com
GREEN_API_INSTANCE=
GREEN_API_TOKEN=
# Legacy/unused — use WEBHOOK_SECRET only (config/services.php maps green_api.webhook_secret)
# GREEN_API_WEBHOOK_TOKEN=

# Opcional (P2)
# SENTRY_LARAVEL_DSN=
# FLARE_KEY=
```

---

## Smoke tests post-deploy

Ejecutar desde el VPS o local contra producción:

```bash
curl -sf https://api.lebytek.com/up
curl -sf https://api.lebytek.com/manifest.webmanifest | jq .name
curl -sfI https://api.lebytek.com/favicon.ico | head -1
curl -sfI https://api.lebytek.com/ | head -1
# Login admin: https://api.lebytek.com/admin/login
# Horizon (auth requerida): https://api.lebytek.com/horizon
# API health (Sanctum token + permiso api.health):
# curl -H "Authorization: Bearer <token>" https://api.lebytek.com/api/v1/health
# Provisioning tenant (waapi):
# curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
#   -H "Idempotency-Key: $(uuidgen)" \
#   -d '{"name":"Test","slug":"test","externalRef":"waapi_org_1"}' \
#   https://api.lebytek.com/api/v1/tenants
# Emitir token plataforma waapi:
# php artisan integration:issue-waapi-token --revoke
```

Checklist:

- [ ] `/up` responde 200
- [ ] `/manifest.webmanifest` refleja `appName` configurado
- [ ] `/favicon.ico` responde PNG
- [ ] Landing `/` carga con logo si branding configurado
- [ ] `/admin/login` accesible; primer login fuerza cambio de contraseña
- [ ] Horizon accesible solo para emails en `HORIZON_ALLOWED_EMAILS`
- [ ] Upload branding persiste en R2 (`UPLOADS_DISK=s3`)

---

## SSL

Let's Encrypt vía CloudPanel/certbot. Renovación automática.

---

## Notas

- **No** usar `admin@sistema.local` / `password` en producción; usar `ProductionSeeder` con `ADMIN_INITIAL_PASSWORD`.
- Generar assets frontend (`npm run build`) en cada deploy; CloudPanel sirve desde `public/build`.
- OpenAPI/Scribe: `knuckleswtf/scribe` va en **`require`** (no `require-dev`) para que `composer install --no-dev` deje las rutas `/docs`, `/docs.openapi` y `/docs.postman` activas. Regenerar en cada deploy con `php artisan scribe:generate` (artefactos en `.gitignore`).
