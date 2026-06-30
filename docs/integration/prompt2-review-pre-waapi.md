# Revisión pre-waapi — cumplimiento de prompt2.md

Auditoría exhaustiva del núcleo **WhatsApiLebytek** contra [`prompt2.md`](../../prompt2.md) y la capa de integración waapi, realizada antes de construir el lado waapi.

**Veredicto general:** el núcleo está **listo para integrar waapi en fase 1** (provisioning + health). El núcleo administrativo cumple ~**80–85%** del prompt2. Las brechas restantes son mayormente P2 (observabilidad, 2FA, vertical WhatsApp, enforcement de módulos) y no bloquean la conexión waapi.

---

## Resumen ejecutivo

| Área | Estado | Bloquea waapi fase 1 |
|------|--------|----------------------|
| Stack Laravel + Inertia + Redis + Horizon | OK | No |
| Multi-tenancy + aislamiento | OK (lectura; escritura cruzada sin test) | No |
| RBAC default-deny + Sanctum | OK | No |
| Seguridad núcleo (uploads, HMAC, idempotency) | OK | No |
| API v1 + contrato waapi | OK | No |
| Vertical WhatsApp | No implementado (por diseño) | No (fase 2) |
| S3 producción | Parcial (local por defecto) | No (waapi no sube archivos a api) |
| Observabilidad Sentry/2FA | Pendiente P2 | No |

---

## 1. Entrega ordenada (prompt2 § líneas 255–275)

| # | Ítem prompt2 | Estado | Evidencia |
|---|--------------|--------|-----------|
| 1 | Estructura carpetas Laravel + `resources/js` | **DONE** | Layout estándar + `resources/js/Pages`, `Components`, `Layouts` |
| 2 | Convención nombres | **DONE** | `config/permissions.php`, tablas prefijadas, ULID público |
| 3 | Política DB prefijos solo propios | **DONE** | `users`, `roles`, `jobs` nativos; `core_*`, `cfg_*`, etc. propios |
| 4 | Migraciones núcleo reversibles | **DONE** | `database/migrations/2026_06_29_*` |
| 5 | Seeders (tenant, admin, menú) | **DONE** | `CoreSeeder`, `ProductionSeeder`, `WaapiServiceSeeder` |
| 6 | Multi-tenancy | **DONE** | `BelongsToTenant`, `TenantContext`, `SetTenantContext` |
| 7 | Auth web + API separados | **DONE** | `routes/web.php` vs `routes/api.php`, Sanctum, `EnsureApiPermission` |
| 8 | Menú dinámico cacheado | **DONE** | `AdminMenuService`, `HandleInertiaRequests` |
| 9 | Dashboard widgets (contrato) | **DONE** | `DashboardWidget`, `DashboardWidgetRegistry`, `config/dashboard.php` |
| 10 | Config cacheada Redis | **DONE** | `ConfigurationService`, tests invalidación |
| 11 | PWA dinámica | **DONE** | `ManifestController`, `public/sw.js`, favicon/branding controllers |
| 12 | Uploads seguros | **DONE** | `SecureUploadService`, tests; S3 requiere env producción |
| 13 | Webhooks HMAC + idempotencia | **PARTIAL** | Middleware OK; tabla `int_webhooks` no existe; controller stub |
| 14 | Colas + Horizon | **DONE** (stubs) | `config/horizon.php`, `TransactionalMessageJob`, `CampaignBatchJob` |
| 15 | Pantallas mínimas admin | **DONE** | Landing, login, layout/tema/branding |
| 16 | Bitácora + seguridad base | **DONE** | `AuditLogService`, append-only, `$fillable` explícito |
| 17 | Tests + CI | **DONE** | 34 archivos Pest, `.github/workflows/tests.yml` |
| 18 | Observabilidad + OpenAPI | **PARTIAL** | Health OK, Scribe en CI; Sentry/Flare solo placeholders |
| 19 | README vertical | **DONE** | `README.md` § "Cómo agregar un vertical" |

---

## 2. Stack obligatorio (prompt2 § líneas 26–39)

| Requisito | Estado | Notas |
|-----------|--------|-------|
| Laravel 11+ / PHP 8.2+ | **DONE** | Laravel 13, PHP 8.3 en `composer.json` |
| MySQL | **DONE** | Producción; tests usan SQLite in-memory |
| Inertia + Vue 3 script setup | **DONE** | |
| Tailwind + Vite | **DONE** | |
| Breeze Inertia-Vue | **DONE** | `/admin/login`; `/register` sigue expuesto (considerar deshabilitar en prod) |
| Redis cache/session/queue | **DONE** | `.env.example` |
| Horizon | **DONE** | |
| spatie/laravel-permission | **DONE** | |
| Sanctum API | **DONE** | |
| S3 uploads | **PARTIAL** | Disco `s3` configurado; default local; paquete AWS puede faltar en lock |
| Secretos cifrados en DB | **DONE** | `int_credenciales` encrypted cast |

---

## 3. Seguridad — evaluación de robustez

### Fortalezas (implementadas y probadas)

| Control | Implementación | Test |
|---------|----------------|------|
| API default-deny | `EnsureApiPermission` — ruta sin `permission:` → 403 | `DefaultDenyTest` |
| Sanctum stateless | `auth:sanctum` en grupo `/api/v1` | `SanctumAuthTest` |
| Idempotency-Key writes | `ApiIdempotencyKey` — 24h cache por user+key | Usado en tests tenant |
| Webhook HMAC SHA-256 | `VerifyWebhookSignature` | `WebhookVerificationTest` |
| Webhook dedupe | `WebhookIdempotency` (`X-Event-Id`) | Tests webhooks |
| Uploads seguros | mimetype real, no SVG, re-encode GD | `SecureUploadTest` |
| Credenciales cifradas | `encrypted:array` en `Credencial` | Modelo |
| Bitácora append-only | `AuditLog` boot bloquea update/delete | `AuditLogServiceTest` |
| Login throttle | `LoginRequest` | Auth tests |
| Mass assignment | `#[Fillable]` en todos los modelos | Revisión código |
| Platform-only tenant ops | `TenantController::ensurePlatformService()` | `TenantProvisioningTest` |
| Acting tenant header | `ResolveActingTenant` valida ULID | `ResolveActingTenantTest` |
| Rate limit API | 60/min por `tenant_id:user_id` | `AppServiceProvider` |

### Brechas de seguridad (mitigar antes de producción masiva)

| Riesgo | Severidad | Estado | Acción recomendada |
|--------|-----------|--------|-------------------|
| Token plataforma waapi único — compromiso total | **Alta** | Aceptado (diseño fase 1) | Rotación con `--revoke`, IP allowlist en VPS, vault para `.env` waapi |
| Sin 2FA admin | Media | P2 | Fortify cuando `AUTH_2FA_ENABLED` |
| `SESSION_SECURE_COOKIE` no documentado en prod | Media | P2 | Añadir a `DEPLOY.md` y `.env` producción |
| Registro público `/register` abierto | Media | Parcial | Deshabilitar en producción si solo admin |
| Tenant write isolation sin test | Baja | Gap test | Añadir test cross-tenant write |
| Guard spatie web vs sanctum en service user | Baja | Funciona | `WaapiServiceSeeder` sincroniza permisos `web` explícitamente |
| Webhook controller stub — no persiste eventos | Baja (pre-vertical) | P2 | Tabla `int_webhooks` al implementar Green |

### Idempotencia — doble capa (correcto para waapi)

1. **HTTP:** `Idempotency-Key` — evita duplicar la misma petición HTTP (reintentos de red).
2. **Negocio:** `externalRef` en `POST /tenants` — evita duplicar tenants waapi aunque cambie el `Idempotency-Key`.

waapi debe implementar **ambas** (ver `waapi-implementation-real.md`).

---

## 4. Deuda técnica proyectada — mitigación

| Ítem prompt2 §219–231 | Mitigado | Evidencia / gap |
|----------------------|----------|-----------------|
| Tests + CI desde inicio | **Sí** | CI + 34 tests; gap: write isolation |
| ULID en API/URLs | **Sí** | `Tenant`, `User`, `Archivo` `public_id` |
| Config tipada | **Sí** | `ConfigurationKey` enum + registry |
| Soft deletes + cascada tenant | **Parcial** | `tenant:purge` soft-delete; anonimización placeholder |
| Observabilidad | **Parcial** | Logging JSON, health, Horizon config; sin Sentry instalado |
| OpenAPI | **Parcial** | Scribe cubre fase 1; vertical pendiente |
| Cuota por tenant | **Parcial** | `TenantUsageService` hook sin wiring |
| Idempotency-Key API | **Sí** | Middleware activo |
| TypeScript + tokens + i18n | **Parcial** | TS y i18n presentes; no 100% strings |

**Conclusión deuda:** la deuda crítica para **no romper multi-tenancy ni seguridad API** está mitigada. La deuda restante está documentada en [`docs/P2_BACKLOG.md`](../P2_BACKLOG.md) y no impide waapi fase 1.

---

## 5. Integración waapi — lo añadido sobre prompt2

| Capacidad | Estado | Archivos clave |
|-----------|--------|----------------|
| `external_ref` en tenants | **DONE** | migración, `TenantProvisioningService` |
| Permisos `tenants.*` | **DONE** | `config/permissions.php` |
| CRUD provisioning HTTP | **DONE** | `TenantController`, Form Requests, Resource |
| Cuenta servicio + token artisan | **DONE** | `WaapiServiceSeeder`, `IssueWaapiTokenCommand` |
| `X-Tenant-Id` acting tenant | **DONE** | `ResolveActingTenant` |
| Health con `actingTenant` | **DONE** | `HealthController` |
| Contrato + delegación docs | **DONE** | `docs/integration/*` |
| Tests integración | **DONE** | 19 tests en `tests/Feature/Api/` |

---

## 6. Lo que waapi NO debe asumir que existe

- Endpoints WhatsApp (instancias, campañas, mensajes) — **fase 2**.
- Webhooks salientes de api hacia waapi — **no diseñado aún**; waapi consulta API (pull) o espera fase 2.
- Credenciales Green en waapi — **prohibido** por diseño.
- Panel admin api para clientes finales — solo ops internos.

---

## 7. Recomendaciones antes de codificar waapi

### Obligatorio

1. Emitir token: `php artisan integration:issue-waapi-token --revoke` en api (prod).
2. Configurar waapi `.env` con `LEBYTEK_API_URL` y `LEBYTEK_API_TOKEN`.
3. Seguir [`waapi-implementation-real.md`](waapi-implementation-real.md) — implementación espejo de medidas api.

### Recomendado en api (no bloqueante)

1. Test de escritura cross-tenant en `TenantIsolationTest`.
2. Documentar `SESSION_SECURE_COOKIE=true` en deploy producción.
3. Deshabilitar `/register` en producción si no aplica.

### Diferir a vertical WhatsApp

1. `int_webhooks` persistencia.
2. `Bus::batch` real + idempotencia de mensajes en jobs.
3. OpenAPI completo con endpoints Green.

---

## 8. Dictamen final

| Pregunta | Respuesta |
|----------|-----------|
| ¿prompt2 núcleo entregado? | **Sí**, con gaps P2 documentados |
| ¿Seguridad robusta para fase 1 waapi? | **Sí**, con rotación de token y secretos en vault |
| ¿Deuda técnica crítica mitigada? | **Sí** |
| ¿Listo para construir waapi? | **Sí** — usar `waapi-implementation-real.md` |

**Siguiente paso:** implementar cliente HTTP y provisioning en `Lebytek_Framework` siguiendo la guía real, no reimplementar lógica del núcleo Laravel.
