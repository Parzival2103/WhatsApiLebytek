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

Horizon gestiona las colas `default`, `transactional`, `webhooks`, `campaigns` y `provisioning` (Redis).

### Scheduler (cron)

Horizon no ejecuta el scheduler de Laravel. En el VPS debe existir un cron que invoque `schedule:run` cada minuto:

```cron
* * * * * cd /home/lebytek-api/htdocs/api.lebytek.com && php artisan schedule:run >> /dev/null 2>&1
```

Eso dispara, entre otros, `webhooks:check-unprocessed` cada 5 minutos (backlog watcher de webhooks sin procesar).

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
# Green send delay (Yellow Card mitigation) — once after deploy that adds green:apply-send-delay.
# Prefer a low-traffic window: each successful setSettings REBOOTS that Green instance;
# settings can take up to ~5 minutes to apply (Green docs).
# php artisan green:apply-send-delay --dry-run
# php artisan green:apply-send-delay
# Expect brief green_state / send blips while instances reboot; do not re-run in a tight loop.
```

Checklist:

- [ ] `/up` responde 200
- [ ] `/manifest.webmanifest` refleja `appName` configurado
- [ ] `/favicon.ico` responde PNG
- [ ] Landing `/` carga con logo si branding configurado
- [ ] `/admin/login` accesible; primer login fuerza cambio de contraseña
- [ ] Horizon accesible solo para emails en `HORIZON_ALLOWED_EMAILS`
- [ ] Upload branding persiste en R2 (`UPLOADS_DISK=s3`)
- [ ] (One-shot after delay feature ships) `php artisan green:apply-send-delay --dry-run` then without `--dry-run` (low traffic; Green reboots each instance)

---

## SSL

Let's Encrypt vía CloudPanel/certbot. Renovación automática.

---

## Notas

- **No** usar `admin@sistema.local` / `password` en producción; usar `ProductionSeeder` con `ADMIN_INITIAL_PASSWORD`.
- Generar assets frontend (`npm run build`) en cada deploy; CloudPanel sirve desde `public/build`.
- OpenAPI/Scribe: `knuckleswtf/scribe` va en **`require`** para generar el spec en cada deploy (`php artisan scribe:generate`). Las rutas `/docs*` **no** se exponen en api.lebytek.com (`add_routes=false`); redirigen a **docs.lebytek.com**. Tras generar, sincronizar artefactos:

```bash
php artisan scribe:generate --no-interaction
node scripts/sync-openapi-to-docs.mjs
# Luego build + deploy docsV2 → docs.lebytek.com
```
