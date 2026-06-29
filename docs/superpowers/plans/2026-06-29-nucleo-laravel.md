# Núcleo Laravel WhatsApiLebytek — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Build the reusable admin core per `docs/spec/prompt2-laravel-nucleo.md` — auth, RBAC, multi-tenant, dynamic menu, config/branding/PWA, queues/Horizon, API Sanctum — without the WhatsApp vertical.

**Architecture:** Laravel-first, single stack Inertia+Vue3+Tailwind+TypeScript. Own tables use module prefixes; third-party tables keep native names. Single-database multi-tenancy via `tenant_id` + global scopes. Web session auth vs API Sanctum token auth strictly separated.

**Tech Stack:** Laravel 13, PHP 8.3+, MySQL, Redis (cache/session/queue/rate-limit), Inertia.js, Vue 3 `<script setup>` + TypeScript, Tailwind 4, Vite, Breeze (Inertia-Vue), spatie/laravel-permission, Sanctum, Horizon, S3-compatible storage, Pest.

## Global Constraints

- NO Filament; NO generic CRUD engine; NO Livewire admin panels.
- Third-party tables unchanged: `users`, `roles`, `permissions`, spatie pivot tables, Laravel infra tables.
- Own tables: `core_*`, `cfg_*`, `log_*`, `int_*`, `sys_*`, `dom_*` (reserved, not in núcleo). Explicit `$table` on models; NO global DB prefix.
- Permissions slug format: `modulo.accion` (e.g. `dashboard.ver`, `configuracion.gestionar`).
- RBAC default-deny: every route declares permission; API `/api/v1/*` versioned; Sanctum for API; Breeze session for `/admin`.
- Multi-tenant: `core_tenants`, `tenant_id` FK on own tables, `BelongsToTenant` trait + global scope; platform admin cross-tenant; tenant users confined.
- Public route keys: ULID (not auto-increment IDs in URLs/API).
- `cfg_configuraciones` via typed key registry — no magic string reads scattered in code.
- Redis for cache, session, queue, rate-limiting; Horizon with separate queues (transactional vs campaigns placeholder).
- Uploads: content-based mimetype, no raw SVG, re-encode images, random names, S3 storage, serve via controller.
- Secrets in DB: encrypted casts on `int_credenciales`.
- Bitácora `log_bitacora`: append-only, no PII in clear text.
- Vue: TypeScript, i18n from start (no hardcoded UI strings), theme CSS variables for colors.
- Tests: Pest; factories for core models; minimum: auth web+Sanctum, RBAC default-deny, tenant isolation test.
- Do NOT implement WhatsApp/Green API vertical logic — only queue plumbing ready for it.

---

### Task 1: Stack bootstrap and project scaffolding

**Files:**
- Modify: `composer.json`, `package.json`, `vite.config.js`, `bootstrap/app.php`, `routes/web.php`, `routes/api.php`, `.env.example`, `phpunit.xml`
- Create: `resources/js/app.ts`, `resources/js/types/index.d.ts`, `lang/es.json` (or i18n structure), `tsconfig.json`

**Deliverables:**
1. Install and configure: `laravel/breeze` (inertia-vue + typescript if available), `spatie/laravel-permission`, `laravel/sanctum`, `laravel/horizon`, `pestphp/pest` + `pestphp/pest-plugin-laravel`.
2. Publish configs/migrations for spatie, sanctum, horizon (do NOT rename spatie tables).
3. Configure `.env.example`: Redis drivers for cache/session/queue; S3 disk vars; `APP_URL`.
4. Set up Inertia root template, `resources/js/Pages`, `Layouts`, `Components`, TypeScript + vue-i18n baseline.
5. Register API routes file with prefix `/api/v1` in `bootstrap/app.php`.
6. Remove/replace default welcome blade-only flow with Inertia-ready `web` routes stub.
7. Ensure `php artisan test` passes (migrate fresh in tests via RefreshDatabase where needed).

**Interfaces — Produces:**
- `routes/api.php` exists with `Route::prefix('v1')` group (empty OK).
- `routes/web.php` has `/` and `/admin` prefix group placeholders.
- Horizon service provider registered; `config/horizon.php` present.

**Acceptance:** `composer install`, `npm install`, `npm run build`, `php artisan test` all pass on clean checkout.

---

### Task 2: Core database migrations

**Files:**
- Create migrations for: `core_tenants`, `cfg_configuraciones`, `cfg_catalogos_auxiliares`, `log_bitacora`, `core_modules`, `core_menu_items`, `core_archivos`, `int_credenciales`, `sys_kv`
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php` — add `tenant_id` nullable FK, `public_id` ULID unique

**Deliverables:**
1. All migrations reversible; snake_case; FKs indexed; soft deletes where spec implies (tenants, relevant entities).
2. Add `tenant_id` to users migration (or new migration altering users).
3. Models stub optional — focus migrations + factories in Task 3.

**Interfaces — Produces:**
- Tables exist with exact names from spec.
- `users.tenant_id` nullable for platform admin.

**Acceptance:** `php artisan migrate:fresh` succeeds; migration rollback works.

---

### Task 3: Multi-tenancy and core models

**Files:**
- Create: `app/Models/Core/Tenant.php`, `app/Models/Concerns/BelongsToTenant.php`, `app/Support/TenantContext.php`, middleware `app/Http/Middleware/SetTenantContext.php`
- Create models for all Task 2 tables under `app/Models/` with explicit `$table`, `$fillable`, ULID route keys where public
- Create factories for all core models
- Modify: `app/Models/User.php` — tenant relation, BelongsToTenant or platform bypass

**Deliverables:**
1. Global scope filters by resolved tenant on models using trait.
2. Creating records auto-sets `tenant_id` from context.
3. Platform admin (null tenant or super flag) can bypass scope — define clear rule (e.g. `users.is_platform_admin` bool or null `tenant_id` + gate).
4. Register middleware in web + api groups.

**Interfaces — Produces:**
- `BelongsToTenant` trait applied to own models.
- `TenantContext::id(): ?string` (or int — match PK type).
- `Core\Tenant` model with ULID public id.

**Acceptance:** Unit/feature test proving tenant A cannot read tenant B data (minimal model query test).

---

### Task 4: RBAC, seeders, and admin user

**Files:**
- Create: `database/seeders/CoreSeeder.php`, `database/seeders/RolesAndPermissionsSeeder.php`, `config/permissions.php` (nucleo permission list)
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `app/Http/Middleware/EnsurePermission.php` (or use spatie middleware)
- Create: Policies base pattern example for default-deny

**Deliverables:**
1. Permissions for núcleo: dashboard, configuracion (layout/colores/branding), usuarios (future), modulos, bitacora (view), etc.
2. Role `admin` with all nucleo permissions.
3. Seed: default tenant, admin user `admin@sistema.local` with password `password` (document must-change in README), assigned admin role.
4. Seed `core_menu_items` skeleton entries linked to permissions.
5. Seed `core_modules` from `config/vertical.php` availability list.

**Interfaces — Produces:**
- `config/vertical.php` — declares available modules (code-only availability).
- Permission slugs registered and usable in routes as `permission:dashboard.ver`.

**Acceptance:** Seeder runs; admin can be authenticated; permission middleware blocks unauthorized routes.

---

### Task 5: Configuration service and typed registry

**Files:**
- Create: `app/Support/Config/ConfigurationRegistry.php`, `app/Support/Config/ConfigurationKey.php` (enum or class constants), `app/Services/ConfigurationService.php`
- Create: cache invalidation on write; share essential config to Inertia via middleware

**Deliverables:**
1. Typed keys: layout mode (top|side), theme colors (JSON map → CSS vars), app name, PWA colors, logo/favicon/pwa icon file ids referencing `core_archivos`.
2. Read through service only; Redis cache with tenant-scoped keys.
3. Default values when missing.

**Interfaces — Produces:**
- `ConfigurationService::get(ConfigurationKey $key): mixed`
- `ConfigurationService::set(ConfigurationKey $key, mixed $value, User $actor): void` — writes bitácora hook optional stub.

**Acceptance:** Feature test: set/get config roundtrip; cache hit verified.

---

### Task 6: Dynamic admin menu

**Files:**
- Create: `app/Services/AdminMenuService.php`, `app/Http/Middleware/ShareAdminMenu.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Create: `resources/js/Components/Admin/AdminMenu.vue`, layout components (AdminLayout with top/side variants)

**Deliverables:**
1. Menu built from `core_menu_items` filtered by user permissions; cached per role+tenant.
2. Shared as `adminMenu` Inertia prop.
3. Layout reads config for top vs side menu.

**Acceptance:** Feature test: user with subset of permissions sees filtered menu items only.

---

### Task 7: Dashboard widget contract

**Files:**
- Create: `app/Contracts/DashboardWidget.php`, `app/Services/DashboardWidgetRegistry.php`, `config/dashboard.php`

**Deliverables:**
1. Interface with `key()`, `permission()`, `data(): array`, optional Vue component name.
2. Registry aggregates registered widgets; no widgets seeded required.
3. Admin dashboard page stub lists registered widgets user can see.

**Acceptance:** Unit test registry returns widgets filtered by permission.

---

### Task 8: Audit log (bitácora)

**Files:**
- Create: `app/Services/AuditLogService.php`, `app/Models/Log/AuditLog.php` (table `log_bitacora`)

**Deliverables:**
1. Append-only writes: actor, action, entity, before/after JSON (sanitized), IP, user agent.
2. No PII/message content in clear text.
3. Used by ConfigurationService on writes (Task 5 integration).

**Acceptance:** Feature test log entry created on config change; no update/delete methods on model.

---

### Task 9: Sanctum API foundation and default-deny

**Files:**
- Modify: `routes/api.php`, `bootstrap/app.php`
- Create: `app/Http/Middleware/EnsureApiPermission.php`, sample `app/Http/Controllers/Api/V1/HealthController.php`
- Create: `tests/Feature/Api/DefaultDenyTest.php`

**Deliverables:**
1. All `/api/v1/*` routes use `auth:sanctum` + permission middleware.
2. Rate limiting per token/tenant via RouteService or bootstrap.
3. API Resources base pattern; JSON camelCase.
4. Test: endpoint without explicit permission fails closed.

**Acceptance:** Sanctum token auth test; default-deny test passes.

---

### Task 10: Secure uploads and core_archivos

**Files:**
- Create: `app/Services/SecureUploadService.php`, `app/Http/Controllers/Admin/BrandingUploadController.php`, `app/Models/Core/Archivo.php`
- Modify: `config/filesystems.php` — S3 disk default for uploads in production example

**Deliverables:**
1. Validate mimetype by content; allowlist jpeg/png/webp; reject SVG; re-encode via GD/Imagick.
2. Random filename; store on S3 disk; metadata in `core_archivos`.
3. Serve via authenticated controller route (not public path).

**Acceptance:** Feature test rejects SVG; accepts PNG; stored file re-encoded.

---

### Task 11: PWA, dynamic manifest, and public landing

**Files:**
- Create: `app/Http/Controllers/ManifestController.php`, `app/Http/Controllers/FaviconController.php`, `public/sw.js` or vite PWA plugin setup
- Create: `resources/js/Pages/Public/Index.vue` (landing minimal)
- Routes: `/`, `/manifest.webmanifest`, `/favicon.ico` dynamic

**Deliverables:**
1. Manifest generated from ConfigurationService; cache headers + version hash.
2. Service worker registers shell assets.
3. Landing shows configurable logo/app name/colors.

**Acceptance:** Feature test manifest returns JSON with configured app name.

---

### Task 12: Webhook verification middleware (base)

**Files:**
- Create: `app/Http/Middleware/VerifyWebhookSignature.php`, `app/Http/Middleware/WebhookIdempotency.php`
- Create stub route `POST /api/v1/webhooks/incoming` (placeholder for int_webhooks future)

**Deliverables:**
1. HMAC signature verification configurable via env secret.
2. Idempotency by event-id header stored in cache/DB dedupe.

**Acceptance:** Feature tests: invalid signature 401; duplicate event-id 409 or 200 idempotent.

---

### Task 13: Horizon and scalable queues

**Files:**
- Modify: `config/horizon.php`, `config/queue.php`
- Create: example jobs `app/Jobs/TransactionalMessageJob.php`, `app/Jobs/CampaignBatchJob.php` (stubs), `app/Jobs/Middleware/RateLimitedWithRedis.php`

**Deliverables:**
1. Queues: `default`, `transactional`, `campaigns` with separate Horizon supervisors.
2. Redis throttle middleware example on stub job.
3. Document Bus::batch/chunking pattern in job stub comments only.

**Acceptance:** Horizon config valid; `php artisan horizon:status` or config test; job dispatches to correct queue.

---

### Task 14: Admin UI — auth and configuration screens

**Files:**
- Breeze auth pages under `resources/js/Pages/Auth/*`
- Create: `resources/js/Pages/Admin/Dashboard.vue`, `Admin/Config/Layout.vue`, `Admin/Config/Theme.vue`, `Admin/Config/Branding.vue`
- Create controllers: `app/Http/Controllers/Admin/Config/*`

**Deliverables:**
1. `/admin` login (Breeze); protected admin routes with permission middleware.
2. Three config screens persist via ConfigurationService + uploads for branding.
3. Theme applies CSS variables from config; layout toggle top/side.

**Acceptance:** Feature tests login + config update; manual build succeeds.

---

### Task 15: Core feature tests and CI

**Files:**
- Create: `tests/Feature/Auth/WebAuthTest.php`, `tests/Feature/Auth/SanctumAuthTest.php`, `tests/Feature/Rbac/DefaultDenyTest.php`, `tests/Feature/Tenancy/TenantIsolationTest.php`
- Create: `.github/workflows/tests.yml`

**Deliverables:**
1. Tenant isolation test mandatory per spec.
2. CI runs `composer install`, `npm ci`, `npm run build`, `php artisan test` on push PR.

**Acceptance:** Full suite green locally and CI yaml valid.

---

### Task 16: Observability, health check, and OpenAPI stub

**Files:**
- Create: `app/Http/Controllers/Api/V1/HealthController.php` (expand), `routes/health.php` or route
- Add Sentry/Flare config placeholders in `.env.example`
- Install `knuckleswtf/scribe` or similar; publish minimal OpenAPI for `/api/v1/health`

**Deliverables:**
1. `/up` or `/api/v1/health` returns DB+Redis status.
2. Structured logging channel config documented.
3. OpenAPI doc generated for public API skeleton.

**Acceptance:** Health endpoint test; scribe generate succeeds.

---

### Task 17: README — adding a vertical

**Files:**
- Modify: `README.md` — section "Cómo agregar un vertical"

**Deliverables:**
1. Document: `dom_*` tables, BelongsToTenant, ULID, API Resources, permissions, menu items, core_modules toggle.

**Acceptance:** Reviewer confirms all spec README bullets covered.

---
