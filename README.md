# WhatsApiLebytek

API Laravel intermediaria para **Green API (WhatsApp)**: colas con Redis, workers con Horizon, webhooks y campañas. Se integra con el SaaS Lebytek en `waapi.lebytek.com`.

Este repositorio incluye el **núcleo administrativo Laravel** (Inertia + Vue 3 + multi-tenant + RBAC) sobre el que se montan verticales de negocio.

## Montaje en producción (VPS)

| Concepto | Valor |
|----------|--------|
| **Dominio** | `https://api.lebytek.com` |
| **Ruta en servidor** | `/home/lebytek-api/htdocs/api.lebytek.com` |
| **Document root (nginx)** | `.../api.lebytek.com/public` |
| **Usuario CloudPanel** | `lebytek-api` |
| **PHP-FPM** | 8.4 (puerto site `20001`) |
| **Colas** | Redis (`127.0.0.1:6379`) |
| **Supervisor** | `/etc/supervisor/conf.d/lebytek-api-horizon.conf` (`php artisan horizon`) |
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

Ver runbook completo en [`docs/DEPLOY.md`](docs/DEPLOY.md) (npm build, R2, ProductionSeeder, smoke tests).

```bash
cd /home/lebytek-api/htdocs/api.lebytek.com
sudo -u lebytek-api git pull origin main
sudo -u lebytek-api composer install --no-dev --optimize-autoloader
sudo -u lebytek-api npm ci && npm run build
sudo -u lebytek-api php artisan migrate --force
sudo -u lebytek-api php artisan config:cache
sudo -u lebytek-api php artisan scribe:generate
supervisorctl restart lebytek-api-horizon:*
```

## Tokens de tema (CSS)

Contrato en [`resources/css/theme.css`](resources/css/theme.css):

- `--color-primary`, `--color-secondary`, `--color-accent`, `--color-background`, `--color-foreground`

Los valores se inyectan desde `appConfig.themeColors` (configuración admin). Los verticales deben usar estas variables, no colores hardcodeados.

> **`.env` no va en git.** En el VPS vive solo en el servidor; cópialo/ajusta una vez en producción.

## Stack previsto

- Laravel 13 · Redis · Horizon · Green API · Jobs/colas · Sanctum · Inertia + Vue 3

Ver `docs/DEPLOY.md` y `docs/spec/prompt2-laravel-nucleo.md`.

## Núcleo local (worktree / rama `feature/nucleo-laravel`)

```bash
cd .worktrees/feature-nucleo-laravel   # o raíz si ya mergeado
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate:fresh --seed
npm install && npm run build
php artisan serve
```

- Panel admin: `/admin/login` — `admin@sistema.local` / `password` (cambiar antes de producción)
- API health: `GET /api/v1/health` con token Sanctum
- Provisioning tenants (back-office lebytek.com): `POST /api/v1/tenants` — ver `docs/integration/waapi-api-contract.md`
- Token plataforma waapi: `php artisan integration:issue-waapi-token`
- OpenAPI: `php artisan scribe:generate` → `/docs`

## Cómo agregar un vertical de dominio

Sigue estas convenciones para montar un módulo de negocio **sin modificar el núcleo**:

1. **Tablas** — Prefijo `dom_*` (reservado para verticales). Toda tabla propia lleva `tenant_id` FK a `core_tenants`.
2. **Modelos** — Usa el trait `App\Models\Concerns\BelongsToTenant` y declara `protected $table = 'dom_mi_recurso'`. Clave pública **ULID** (`public_id`) como route key; PK interna autoincremental.
3. **API** — Controllers en `app/Http/Controllers/Api/V1/`, respuestas con **API Resources** (JSON camelCase), rutas bajo `/api/v1/...` con `auth:sanctum`, `ensure.api.permission` y middleware `permission:modulo.accion`.
4. **Permisos** — Slug `modulo.accion` (ej. `campanias.crear`). Regístralos en seeders/config y asígnalos a roles vía spatie.
5. **Menú admin** — Inserta filas en `core_menu_items` (global o por tenant) con el permiso correspondiente.
6. **Toggle de módulo** — Declara disponibilidad en `config/vertical.php`; estado on/off autoritativo en `core_modules` por tenant.
7. **Colas** — Usa colas `transactional` o `campaigns` según prioridad; jobs con idempotencia y rate-limit Redis (ver stubs en `app/Jobs/`).
8. **No tocar** tablas nativas de terceros (`users`, `roles`, `permissions`, `jobs`, etc.).

Ejemplo mínimo de modelo de vertical:

```php
namespace App\Models\Domain\Campaña;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Campaña extends Model
{
    use BelongsToTenant;

    protected $table = 'dom_campanias';

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
```
