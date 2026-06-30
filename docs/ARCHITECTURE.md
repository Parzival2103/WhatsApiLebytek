# Arquitectura — ecosistema Lebytek

## Ecosistema (2026-06-30)

| Dominio | Rol hoy | Repo | Integración api |
|---------|---------|------|-----------------|
| **lebytek.com** | Back-office + landing; FTP México = legacy pre-1.0 | Lebytek_Framework (`feature/backoffice-api-integration`) | **Orquesta** (Bearer plataforma) |
| **api.lebytek.com** | Motor WhatsApp + admin ops | WhatsApiLebytek (`main`) | Fuente de verdad técnica |
| **waapi.lebytek.com** | Panel cliente (fase final) | congelado en VPS | Solo lectura futura; no orquestador |
| **docs.lebytek.com** | Docs públicas | placeholder | Sin cambios |

**Deploy:** lebytek.com target VPS `/home/lebytek/htdocs/lebytek.com` (docroot `public/`). DNS cutover after E2E.

## Responsabilidades

**lebytek.com (back-office):** landing pública, panel de leads, orquestación de provisioning en api con token de plataforma, comunicación al cliente por correo.

**waapi.lebytek.com:** panel cliente en fase posterior; congelado en VPS; no orquesta provisioning.

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
