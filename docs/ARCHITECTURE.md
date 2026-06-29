# Arquitectura — dos productos Lebytek

```
                    ┌─────────────────────────────┐
                    │   waapi.lebytek.com         │
                    │   Lebytek Framework         │
                    │   (skeleton / monorepo)     │
                    │   Admin, CRUD, RBAC, UI     │
                    └──────────────┬──────────────┘
                                   │ HTTP /api/v1 (futuro)
                                   ▼
                    ┌─────────────────────────────┐
                    │   api.lebytek.com           │
                    │   WhatsApiLebytek (Laravel) │
                    │   Green API, colas, campañas│
                    └──────────────┬──────────────┘
                                   │
                                   ▼
                         Green API (WhatsApp)
```

## Responsabilidades

**waapi (Framework):** panel SaaS, usuarios finales, configuración de negocio, hostings compartidos simples.

**api (Laravel):** envío masivo, rate limits, webhooks Green API, jobs Redis, credenciales cifradas por tenant, API pública versionada.

## Repos

- Framework: https://github.com/Parzival2103/Lebytek_Framework
- API: https://github.com/Parzival2103/WhatsApiLebytek

## Pendiente en VPS

El sitio `api.lebytek.com` aún tiene una instalación Laravel temporal **sin** `git remote` al repo. Al empezar desarrollo serio: clonar `WhatsApiLebytek` en `/home/lebytek-api/htdocs/api.lebytek.com` (ver `DEPLOY.md`) y conservar el `.env` de producción.
