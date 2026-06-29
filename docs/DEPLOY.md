# Deploy — api.lebytek.com

## Ubicación en el VPS

```
/home/lebytek-api/htdocs/api.lebytek.com/   ← raíz del repo (artisan, app/, config/)
/home/lebytek-api/htdocs/api.lebytek.com/public/   ← document root nginx
```

CloudPanel site user: **lebytek-api**

## Primera vez (clonar repo)

```bash
cd /home/lebytek-api/htdocs
# respaldar instalación previa si existe
mv api.lebytek.com api.lebytek.com.bak-$(date +%F) 2>/dev/null || true
git clone https://github.com/Parzival2103/WhatsApiLebytek.git api.lebytek.com
chown -R lebytek-api:lebytek-api api.lebytek.com
cd api.lebytek.com
sudo -u lebytek-api cp .env.example .env
# editar .env (DB, Redis, GREEN_API_*)
sudo -u lebytek-api composer install --no-dev --optimize-autoloader
sudo -u lebytek-api php artisan key:generate
sudo -u lebytek-api php artisan migrate --force
sudo -u lebytek-api php artisan config:cache
supervisorctl restart lebytek-api-worker:*
```

## SSL

Let's Encrypt ya instalado vía CloudPanel/certbot. Renovación automática con certbot.

## Variables `.env` producción (referencia)

```env
APP_URL=https://api.lebytek.com
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1

DB_DATABASE=lebytekapi
DB_USERNAME=lebytekapi
# DB_PASSWORD → CloudPanel → Databases

GREEN_API_BASE_URL=https://api.green-api.com
GREEN_API_INSTANCE=
GREEN_API_TOKEN=
GREEN_API_WEBHOOK_TOKEN=
```
