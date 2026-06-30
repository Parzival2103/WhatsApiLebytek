# Arquitectura — ecosistema Lebytek

## Tres dominios

```
  lebytek.com                    waapi.lebytek.com
  (marketing / leads)            (SaaS v2 + panel cliente)
        │                                  │
        │  CTA / enlace                    │ HTTP /api/v1
        └──────────────►───────────────────┤ Bearer Sanctum
                                           │ Idempotency-Key
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

| Dominio | Rol | Repo | Integración |
|---------|-----|------|-------------|
| **lebytek.com** | Web corporativa, CRM/leads | Lebytek Framework (hosting simple) | Ninguna con api |
| **waapi.lebytek.com** | Catálogo v2, registro, panel cliente | [Lebytek_Framework](https://github.com/Parzival2103/Lebytek_Framework) | Consume api vía REST |
| **api.lebytek.com** | Motor técnico multi-tenant | [WhatsApiLebytek](https://github.com/Parzival2103/WhatsApiLebytek) | Proveedor para waapi |

## Responsabilidades

**lebytek.com:** presencia pública, formularios de contacto, enlaces al producto. No aloja lógica WhatsApp ni provisioning de tenants.

**waapi (Framework):** panel SaaS, usuarios finales, catálogo de productos, orquestación de clientes. Provisiona tenants en api con token de plataforma.

**api (Laravel):** envío masivo, rate limits, webhooks Green API, jobs Redis, credenciales cifradas por tenant, API pública versionada `/api/v1`.

## Contrato de integración waapi ↔ api

- **Contrato técnico:** [docs/integration/waapi-api-contract.md](integration/waapi-api-contract.md)
- **Delegación de roles (para repo waapi):** [docs/integration/role-delegation-waapi.md](integration/role-delegation-waapi.md)
- **Implementación concreta waapi (código + checklist):** [docs/integration/waapi-implementation-real.md](integration/waapi-implementation-real.md)
- **Auditoría prompt2 pre-waapi:** [docs/integration/prompt2-review-pre-waapi.md](integration/prompt2-review-pre-waapi.md)
- **Checklist VPS:** [docs/integration/VPS_CHECKLIST.md](integration/VPS_CHECKLIST.md)

Fase 1 activa: provisioning de tenants (`POST /tenants`), health, cuenta de servicio Sanctum (`php artisan integration:issue-waapi-token`).

## Repos

- Framework: https://github.com/Parzival2103/Lebytek_Framework
- API: https://github.com/Parzival2103/WhatsApiLebytek

## Pendiente en VPS

El sitio `api.lebytek.com` debe apuntar al repo `WhatsApiLebytek` en `/home/lebytek-api/htdocs/api.lebytek.com` (ver [DEPLOY.md](DEPLOY.md)). Conservar `.env` de producción al clonar/actualizar.
