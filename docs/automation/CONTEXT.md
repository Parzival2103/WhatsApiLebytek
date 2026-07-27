# Contexto para automatizaciones — ecosistema Lebytek

Documento de referencia para Cursor Automations. Mantener alineado con `docs/ARCHITECTURE.md`.

## Mapa de productos

```
lebytek.com / waapi (Lebytek_Portal) ──Bearer plataforma──► api.lebytek.com
                 │                                           │
                 │ Marketing, leads, membresías               │ WhatsApp, colas
                 ▼                                           ▼
        lebytek/framework vía Composer                     Green API
docs.lebytek.com (mirror público de docs)
```

## WhatsApiLebytek — detalle técnico

### Stack verificado

- PHP `^8.3`, Laravel **13** (lock en `composer.lock`)
- Vue 3 + Inertia + TypeScript + Vite 8 + Tailwind 3
- Redis: cache, session, queues (`QUEUE_CONNECTION=redis`)
- Horizon: colas `default`, `transactional`, `campaigns`, `provisioning`
- Sanctum + spatie/laravel-permission
- Pest 4 (~100 tests), CI en GitHub Actions
- Scribe para OpenAPI en `/docs`

### Superficies críticas a auditar

| Área | Ubicación | Riesgo |
|------|-----------|--------|
| API v1 | `routes/api.php`, `app/Http/Controllers/Api/V1/` | Alto — contrato con Portal |
| Webhooks | `IncomingWebhookController`, middleware firma/idempotencia | Alto |
| RBAC | `config/permissions.php`, `ensure.api.permission` | Alto |
| Jobs | `app/Jobs/*`, `RateLimitedWithRedis` | Medio |
| Migraciones | `database/migrations/` | Alto si destructivas |
| Admin Inertia | `routes/web.php`, `resources/js/` | Medio |
| Integración plataforma | `integration:issue-waapi-token`, `TenantTokenService` | Alto |

### Módulos / verticales incompletos (deuda conocida)

- `CampaignBatchJob` — stub; campañas masivas no cerradas
- Docs dicen "Laravel 11+" pero runtime es Laravel 13
- `routes/console.php` — sin tareas programadas Laravel (cron VPS externo)
- Cobertura CI sin reporte de coverage

### VPS api.lebytek.com (solo referencia, no tocar desde automation)

- Path: `/home/lebytek-api/htdocs/api.lebytek.com`
- Supervisor: `lebytek-api-horizon` → `php artisan horizon`
- Deploy manual: `git pull origin main` + migrate + cache (ver `docs/DEPLOY.md`)

## Ecosistema Framework / Portal — auditoría cruzada

Usar `gh` para leer el repo hermano; no asumir checkout local.

### Lebytek_Framework (package source)

- Rama canónica para auditoría, spec, plan e implementación: `main`
- PHP 8.1+, framework propio Onion (`src/`)
- Sin Vue/npm; PHP views + Bootstrap CDN + `crud-engine.js`
- MySQL/MariaDB, PHPMailer, dompdf
- Tests: harness `microtest` (`php tests/run.php`)
- No es la app desplegable y no debe contener negocio Marketing/Portal
- `feature/backoffice-api-integration` es referencia histórica; nunca base de trabajo nuevo

### Lebytek_Portal (app desplegable)

- Rama canónica y deploy: `main`
- Dueño de Marketing, leads, membresías, landing y orquestación api
- Consume `lebytek/framework` mediante versión semver fijada en `composer.lock`
- Producción lebytek.com/waapi fue migrada a Portal el 2026-07-21
- Verificar estado actual en el repo Portal; no usar scripts legacy del Framework como fuente de verdad

### Integración api

- Portal: `app/Infrastructure/Integrations/LebytekApi/LebytekApiClient.php`
- `LeadApiProvisioningService`, portal waapi (`WAAPI_PORTAL_ENABLED`)
- Framework genérico llega a Portal por Composer, no copiando código ni editando `vendor/`

### Referencias legacy

- Los scripts `vps-deploy-lebytek-com.sh` / `vps-deploy-waapi.sh` que clonan
  Framework feature son pre-cutover y no son autoridad para planes nuevos.
- No mergear `feature/backoffice-api-integration` → `main` salvo orden explícita.

## docs.lebytek.com

- Sync: `npm run sync` desde `scripts/sync-docs.mjs`
- Riesgo: drift entre repos fuente y mirror publicado

## Señales de riesgo a buscar en auditoría diaria

### Seguridad

- Rutas API sin middleware de permiso
- Webhooks sin firma/idempotencia
- Secretos en código o commits recientes
- CORS demasiado permisivo (`config/cors.php`)
- Mass assignment, validación faltante en Form Requests

### Laravel / PHP

- Migraciones con `drop`, `truncate`, cambios en columnas sensibles sin rollback
- N+1 en controllers, jobs sin `failed()` o retry policy
- `DB::raw` sin binding, SQL injection
- Policies faltantes en modelos expuestos

### Frontend

- Errores TypeScript en `npm run build`
- Páginas Inertia sin permisos alineados con backend

### Deploy / VPS

- Cambios en `.env.example` sin documentar en DEPLOY
- Nuevas colas sin entrada en `config/horizon.php`
- Nuevos permisos RBAC sin seeder/sync script
- Contrato api cambiado sin actualizar `docs/integration/`

### Tests

- Endpoints nuevos sin test Feature en `tests/Feature/Api/`
- Regresiones en CI (últimos commits vs workflow)

## Comandos seguros para el agente (repo API)

```bash
git log --oneline -20
git diff main...HEAD --stat
composer test
npm run build
php artisan route:list
php artisan migrate:status  # solo si hay DB local; nunca contra prod
```

## Comandos prohibidos en automatizaciones

```bash
ssh *
git push origin main
php artisan migrate --force   # en contexto prod
composer deploy *
./scripts/vps-*
curl *api.lebytek.com*        # con side effects (POST/DELETE)
```
