# Arquitectura — ecosistema Lebytek

## Ecosistema (actualizado 2026-07-24)

| Dominio | Rol hoy | Repo | Integración api |
|---------|---------|------|-----------------|
| **lebytek.com / waapi** | Portal, back-office, landing y membresías | Lebytek_Portal (`main`) | **Orquesta** (Bearer plataforma) |
| **Framework package** | Kernel, RBAC, CRUD Engine, Payments genérico | Lebytek_Framework (`main`, semver) | Librería consumida por Portal |
| **api.lebytek.com** | Motor WhatsApp + admin ops | WhatsApiLebytek (`main`) | Fuente de verdad técnica |
| **docs.lebytek.com** | Docs públicas | placeholder | Sin cambios |

**Deploy:** lebytek.com y waapi despliegan `Lebytek_Portal/main`; Framework
llega mediante Composer y la versión fijada en `composer.lock`.

## Responsabilidades

**Portal (lebytek.com / waapi):** landing pública, panel de leads,
orquestación de provisioning en api con token de plataforma y comunicación al
cliente por correo.

**Framework:** plataforma reutilizable sin negocio Portal y sin deploy web
propio. `feature/backoffice-api-integration` queda como referencia histórica,
no como base para trabajo nuevo.

**api (Laravel):** envío masivo, rate limits, webhooks Green API, jobs Redis, credenciales cifradas por tenant, API pública versionada `/api/v1`.

## Contrato de integración

- [waapi-api-contract.md](integration/waapi-api-contract.md) — HTTP v1
- [role-delegation-lebytek-api.md](integration/role-delegation-lebytek-api.md) — responsabilidades
- [lebytek-implementation-real.md](integration/lebytek-implementation-real.md) — guía Framework
- [prompt2-review-pre-waapi.md](integration/prompt2-review-pre-waapi.md) — auditoría histórica api
- [VPS_CHECKLIST.md](integration/VPS_CHECKLIST.md)

Fase 1 activa: provisioning de tenants (`POST /tenants`), health, cuenta de servicio Sanctum (`php artisan integration:issue-waapi-token`).

## Repos

- Framework: https://github.com/Parzival2103/Lebytek_Framework
- API: https://github.com/Parzival2103/WhatsApiLebytek

## Pendiente en VPS

El sitio `api.lebytek.com` debe apuntar al repo `WhatsApiLebytek` en `/home/lebytek-api/htdocs/api.lebytek.com` (ver [DEPLOY.md](DEPLOY.md)). Conservar `.env` de producción al clonar/actualizar.
