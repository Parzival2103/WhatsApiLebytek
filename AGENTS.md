# AGENTS.md — WhatsApiLebytek (api.lebytek.com)

Guía para agentes de IA y **Cursor Automations** en este repositorio.

## Qué es este repo

| Campo | Valor |
|-------|--------|
| Producto | API Laravel multi-tenant WhatsApp (Green API) |
| Dominio prod | `api.lebytek.com` |
| GitHub | `Parzival2103/WhatsApiLebytek` |
| Rama deploy VPS | `main` |
| Stack | PHP 8.3+, Laravel 13, Inertia+Vue 3, Redis, Horizon, Sanctum, Pest |

## Ecosistema Lebytek (no confundir repos)

| Dominio | Repo | Rama activa VPS | Rol |
|---------|------|-----------------|-----|
| api.lebytek.com | **Este repo** | `main` | Motor WhatsApp, colas, webhooks, API `/api/v1` |
| lebytek.com / waapi | `Parzival2103/Lebytek_Framework` | `feature/backoffice-api-integration` | Back-office, leads, CRUD Engine, orquestación api |
| docs.lebytek.com | `Parzival2103/docs.lebytek.com` | `main` | Hub Docsify (mirror de docs) |

**Regla crítica:** no fusionar `feature/backoffice-api-integration` → `main` en Framework sin orden explícita del usuario.

## Lectura obligatoria antes de auditar o cambiar código

1. `CLAUDE.md` — límites del producto y comandos
2. `docs/ARCHITECTURE.md` — mapa del ecosistema
3. `docs/automation/CONTEXT.md` — contexto ampliado para automatizaciones
4. `docs/integration/waapi-api-contract.md` — contrato HTTP v1
5. `docs/DEPLOY.md` — runbook VPS (solo lectura para automations)

## Estructura clave

```
app/Http/Controllers/Api/V1/   # API REST
app/Jobs/                      # Colas: transactional, campaigns, provisioning
app/Services/GreenApi/         # Integración Green API
config/horizon.php             # Workers Redis
config/permissions.php         # RBAC API
routes/api.php                 # Rutas v1 + webhooks
tests/Feature/Api/             # Tests de contrato
docs/integration/              # Contratos con back-office
```

## Comandos de verificación (local / CI)

```bash
composer test
npm run build
php artisan scribe:generate
```

CI: `.github/workflows/tests.yml` (PHP 8.3, Node 22, Redis 7).

## Política para automatizaciones

Ver `docs/automation/README.md` y `.cursor/rules/automation-safety.mdc`.

**Prohibido sin revisión humana explícita:**

- Deploy, SSH, `git push` a producción, cambios en VPS
- Editar `.env` de producción o secretos
- `migrate` en producción
- Merge a `main` o cambios destructivos en BD
- Desactivar tests, Horizon, RBAC o firmas de webhook

**Permitido con criterio:**

- Leer repo, tests, docs, `git log`, diff
- Escribir reportes en `docs/automation-reports/`
- Abrir PR con fixes triviales (typos, docs, linter)
- Abrir issue para riesgo medio/alto

## Automatizaciones definidas

| ID | Archivo | Trigger |
|----|---------|---------|
| AUTOMATION-01 | `docs/automation/AUTOMATION-01-daily-audit.md` | Diario ~08:00 |
