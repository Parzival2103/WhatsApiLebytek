# CLAUDE.md — WhatsApiLebytek

API Laravel en **api.lebytek.com**: intermediario **Green API (WhatsApp)**, colas Redis, campañas, webhooks, multi-tenant.

## No confundir con el otro producto

| Producto | Repo | Dominio | Stack |
|----------|------|---------|-------|
| **Portal Lebytek** | `Parzival2103/Lebytek_Portal` (`main`) | `lebytek.com` / `waapi.lebytek.com` | App PHP consumidora |
| **Framework package** | `Parzival2103/Lebytek_Framework` (`main`) | Sin dominio propio | Composer `lebytek/framework` |
| **Esta API** | `Parzival2103/WhatsApiLebytek` | `api.lebytek.com` | Laravel 13, Inertia+Vue, Horizon, Sanctum |

**No usar Portal, skeleton ni paquete `lebytek/framework` aquí.** Solo Laravel.

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

Portal consume esta API (REST `/api/v1`, Sanctum). No duplicar lógica Green API en Portal ni en el Framework.

- Contrato API: `docs/integration/waapi-api-contract.md`
- Delegación roles (lebytek.com ↔ api): `docs/integration/role-delegation-lebytek-api.md`
- Guía implementación back-office: `docs/integration/lebytek-implementation-real.md`
- Spec alineación docs: `docs/superpowers/specs/2026-06-30-integration-docs-alignment-design.md`
- Auditoría prompt2: [`docs/integration/prompt2-review-pre-waapi.md`](docs/integration/prompt2-review-pre-waapi.md)
- Token de plataforma: `php artisan integration:issue-waapi-token`
