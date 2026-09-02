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
| lebytek.com / waapi | `Parzival2103/Lebytek_Portal` | `main` | App, Marketing, leads y orquestación api |
| package plataforma | `Parzival2103/Lebytek_Framework` | `main` (release semver) | Kernel, RBAC, CRUD Engine y Payments genérico |
| docs.lebytek.com | `Parzival2103/docs.lebytek.com` | `main` | Hub Docsify (mirror de docs) |

**Regla crítica:** no fusionar `feature/backoffice-api-integration` → `main` en Framework sin orden explícita del usuario.
Esa feature es referencia histórica y no es base para auditorías, specs, planes
o implementación nueva.

## División de trabajo (sesión interactiva)

| Rol | Responsabilidad |
|-----|-----------------|
| **Agente** | Programar, auditar, tests locales/CI, ops VPS (`ssh lebytek-vps`), cerrar issues con evidencia |
| **Humano** | Diseñar, testear producto, conformidad con el cliente final |

Los planes en `docs/superpowers/plans/` **pueden y deben** incluir tasks de ops (deploy, migrate, Horizon, cron, smoke) ejecutables por el agente vía `ssh lebytek-vps` cuando el usuario pide ejecutar el plan / SDD. No marcar esos tasks como "human-only" por defecto.

## Lectura obligatoria antes de auditar o cambiar código

1. `CLAUDE.md` — límites del producto y comandos
2. **`map/CLAUDE.md`** — ICM system map (endpoints, cupo instancias, procesos; abre solo la card que necesites)
3. `docs/ARCHITECTURE.md` — mapa del ecosistema
4. `docs/automation/CONTEXT.md` — contexto ampliado para automatizaciones
5. `docs/integration/waapi-api-contract.md` — contrato HTTP v1
6. `docs/DEPLOY.md` — runbook VPS (también ejecutable por el agente en sesión autorizada)

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

## Flujo de entrega (sesión interactiva / planes)

Cuando el trabajo es de producto (no AUTOMATION-01), el agente sigue:

1. **Commit** en rama de trabajo (si el usuario lo pide)
2. **PR** hacia la rama base correcta
3. **CI green** antes de merge
4. **Revisar ramas abiertas** (`gh pr list`, ramas `feature/*` / `automation/*`) para no pisar trabajo en curso
5. **Merge a `main`** solo si aplica a **este repo** (WhatsApiLebytek) y no hay conflicto con trabajo explícito en otra rama

### Excepción Framework (obligatoria)

- **No** mergear `Lebytek_Framework` `feature/backoffice-api-integration` → `main` salvo orden explícita del usuario.
- Framework no se despliega como sitio: se publica por semver y Portal lo instala por Composer.
- Deploy de lebytek.com/waapi = `Lebytek_Portal/main` con `composer.lock`.
- Si el usuario está trabajando explícitamente en esa feature (u otra rama nombrada), no fusionar ni “limpiar” esa rama hacia `main` por iniciativa del agente.

## Ops VPS (sesión interactiva)

Acceso: alias SSH `lebytek-vps`. Preferir `ssh lebytek-vps '…'` no interactivo. Runbook: `docs/DEPLOY.md`.

**Permitido** al ejecutar un plan / orden de deploy en chat:

- `git pull` de la rama de deploy del sitio
- `composer install --no-dev`, `npm ci` / `npm run build`
- `php artisan migrate --force`, caches, `scribe:generate`
- `supervisorctl restart …-horizon:*`
- Verificar/instalar cron `schedule:run`
- Smokes HTTP (`/up`, endpoints documentados)
- Actualizar checklists / cerrar issues con evidencia (SHA, migrate, Horizon, cron)

**Ops prohibidos siempre:**

- Editar `.env` de producción o secretos
- `git push --force`
- Borrar BD / datos
- Merge Framework `feature/backoffice-api-integration` → `main`
- Desactivar RBAC, firmas de webhook, Horizon o tests

## Política para Cursor Automations (no supervisadas)

Ver `docs/automation/README.md` y `.cursor/rules/automation-safety.mdc`.

Las automatizaciones diarias **no** reemplazan ops: sin SSH, sin deploy VPS, sin merge a `main`. Solo lectura, reportes, issues y como máximo un PR trivial en `automation/*`.

**Permitido en automations:**

- Leer repo, tests, docs, `git log`, diff
- Escribir reportes en `docs/automation-reports/`
- Abrir PR con fixes triviales (typos, docs, linter) en rama `automation/*`
- Abrir issue para riesgo medio/alto

## Automatizaciones definidas

| ID | Archivo | Trigger |
|----|---------|---------|
| AUTOMATION-01 | `docs/automation/AUTOMATION-01-daily-audit.md` | Diario ~08:00 |
| AUTOMATION-02 | [Prompt en Framework](https://github.com/Parzival2103/Lebytek_Framework/blob/main/docs/automation/AUTOMATION-02-audit-to-spec.md) | Después de auditoría elegible |
| AUTOMATION-03 | [Prompt en Framework](https://github.com/Parzival2103/Lebytek_Framework/blob/main/docs/automation/AUTOMATION-03-spec-to-plan.md) | Después de spec elegible |
