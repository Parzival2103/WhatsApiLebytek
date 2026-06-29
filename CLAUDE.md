# CLAUDE.md — WhatsApiLebytek

API Laravel en **api.lebytek.com**: intermediario **Green API (WhatsApp)**, colas Redis, campañas, webhooks, multi-tenant.

## No confundir con el otro producto

| Producto | Repo | Dominio | Stack |
|----------|------|---------|-------|
| **SaaS admin Lebytek** | `Parzival2103/Lebytek_Framework` (skeleton) | `waapi.lebytek.com` | PHP framework propio (Onion), hostings simples |
| **Esta API** | `Parzival2103/WhatsApiLebytek` | `api.lebytek.com` | Laravel 11+, Inertia+Vue, Horizon, Sanctum |

**No usar skeleton ni paquete `lebytek/framework` aquí.** Solo Laravel.

## VPS

- Ruta: `/home/lebytek-api/htdocs/api.lebytek.com`
- Document root: `.../public`
- Usuario CloudPanel: `lebytek-api`
- Redis + Supervisor workers ya configurados en el servidor
- Ver `docs/DEPLOY.md`

## Spec del núcleo Laravel

Implementación guiada por `docs/spec/prompt2-laravel-nucleo.md` (stack, RBAC, multi-tenant, colas, Green API vertical).

## Comandos

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
npm install && npm run dev
php artisan serve
php artisan queue:work redis
```

## Integración con waapi

El SaaS en waapi consume esta API (REST `/api/v1`, Sanctum). No duplicar lógica Green API en el framework PHP del skeleton.
