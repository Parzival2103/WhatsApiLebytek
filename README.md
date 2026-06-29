# WhatsApiLebytek

API Laravel intermediaria para **Green API (WhatsApp)**: colas con Redis, workers con Supervisor, webhooks y campañas. Se integra con el SaaS Lebytek en `waapi.lebytek.com`.

## Montaje en producción (VPS)

| Concepto | Valor |
|----------|--------|
| **Dominio** | `https://api.lebytek.com` |
| **Ruta en servidor** | `/home/lebytek-api/htdocs/api.lebytek.com` |
| **Document root (nginx)** | `.../api.lebytek.com/public` |
| **Usuario CloudPanel** | `lebytek-api` |
| **PHP-FPM** | 8.4 (puerto site `20001`) |
| **Colas** | Redis (`127.0.0.1:6379`) |
| **Supervisor** | `/etc/supervisor/conf.d/lebytek-api-worker.conf` |
| **BD CloudPanel** | `lebytekapi` |

## Desarrollo local (Cursor)

```bash
git clone https://github.com/Parzival2103/WhatsApiLebytek.git
cd WhatsApiLebytek
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
npm install && npm run dev
php artisan serve
```

## Deploy en VPS (pull)

```bash
cd /home/lebytek-api/htdocs/api.lebytek.com
sudo -u lebytek-api git pull origin main
sudo -u lebytek-api composer install --no-dev --optimize-autoloader
sudo -u lebytek-api php artisan migrate --force
sudo -u lebytek-api php artisan config:cache
supervisorctl restart lebytek-api-worker:*
```

> **`.env` no va en git.** En el VPS vive solo en el servidor; cópialo/ajusta una vez en producción.

## Stack previsto

- Laravel 11+ · Redis · Horizon (fase 2) · Green API · Jobs/colas

Ver `docs/DEPLOY.md` y `prompt2-laravel-nucleo.md` (spec del núcleo admin) en el repo del framework si aplica.
