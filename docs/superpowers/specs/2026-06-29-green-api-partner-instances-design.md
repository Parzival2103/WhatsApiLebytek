# Diseño — Fase 2: creación de instancias Green API Partner

**Fecha:** 2026-06-29
**Repo:** `WhatsApiLebytek` (`api.lebytek.com`)
**Estado:** Aprobado (brainstorming) — pendiente de plan de implementación

## Contexto

`api.lebytek.com` es el motor técnico multi-tenant que intermedia Green API. La Fase 1 (provisioning de tenants) ya está en `main`. Esta Fase 2 añade la **creación automática de instancias de WhatsApp** usando la **API Partner de Green API**, que permite crear instancias programáticamente con un `partnerToken`.

El consumidor es `waapi.lebytek.com`: cuando el admin aprueba un lead de demo, waapi llama a este endpoint con el token de plataforma para provisionar la instancia demo del cliente.

**`api.lebytek.com` es el dueño de la instancia, no un proxy.** Lo que este repo hace con cada instancia nueva:

1. La crea vía Partner (`createInstance`) y guarda `idInstance` + `apiTokenInstance` **cifrado**, scoped al tenant.
2. La configura (`setSettings`) para apuntar su `webhookUrl` → `https://api.lebytek.com/api/v1/webhooks/incoming` con `webhookUrlToken = WEBHOOK_SECRET`, de modo que todos los eventos (mensajes + cambios de estado) entren al pipeline de colas de este repo.
3. Expone estado + QR para que el cliente del demo vincule su WhatsApp.
4. Recibe `stateInstanceChanged` por el webhook existente y voltea el estado a `authorized` automáticamente.
5. Queda como base para el envío de Fase 2 (campañas/mensajes usan el token por-instancia) y deja un hook para contador de uso por tenant.

## Decisiones de diseño (confirmadas)

| Decisión | Elección |
|---|---|
| Flujo de creación | **Asíncrono + polling**: POST crea registro local y despacha Job; waapi consulta `GET /instances/{id}` hasta `authorized`. |
| Cardinalidad | **Varias instancias por tenant** (demo crea 1, pero el modelo no se queda corto). |
| Almacenamiento | **Tabla `int_instancias` nueva** con lifecycle y QR; `int_credenciales` no alcanza. |
| Alcance | **Solo crear/configurar/estado + contador de uso**. Expiración de demo, topes de mensajes y facturación quedan fuera (YAGNI). |

## Datos: tabla `int_instancias`

Vertical de integración (prefijo `int_`), trait `BelongsToTenant`, `public_id` ULID como route key.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | autoincremental interno |
| `public_id` | ULID, unique | route key público |
| `tenant_id` | FK `core_tenants` cascadeOnDelete | scoping multi-tenant |
| `id_instance` | string, nullable, unique | `idInstance` de Green (null hasta crearse) |
| `api_token_instance` | text, cast `encrypted` | **nunca** se expone en Resources |
| `provider` | string, default `green_api` | proveedor |
| `label` | string | nombre amistoso |
| `purpose` | string, default `demo` | `demo` / `production` |
| `status` | string | lifecycle local (ver abajo) |
| `green_state` | string, nullable | último `stateInstance` de Green |
| `external_ref` | string, nullable | idempotencia waapi |
| `qr_code` | text, nullable | QR base64 cacheado |
| `qr_expires_at` | timestamp, nullable | expiración del QR cacheado |
| `last_error` | text, nullable | último error de provisioning |
| `meta` | json, nullable | extensible |
| `authorized_at` | timestamp, nullable | momento de autorización |
| `created_at` / `updated_at` / `deleted_at` | timestamps + softDeletes | |

**Índices:** `unique(id_instance)`, `unique(tenant_id, external_ref)`, `index(tenant_id, status)`.

### Lifecycle `status`

```
provisioning ──► configuring ──► waiting_qr ──► authorized
     │                │               │
     └────────────────┴───────────────┴──► failed
authorized ──► deleting ──► deleted   (DELETE)
```

- `provisioning`: registro creado, Job en cola.
- `configuring`: instancia creada en Green, fijando webhook (`setSettings`).
- `waiting_qr`: configurada, esperando vinculación (Green state `notAuthorized`).
- `authorized`: Green state `authorized`, lista para enviar.
- `failed`: provisioning agotó reintentos (`last_error` poblado).
- `deleting` / `deleted`: teardown vía Partner `deleteInstanceAccount`.

El mapeo de `green_state` (`authorized`, `notAuthorized`, `blocked`, `sleepMode`, `starting`) llega por webhook `stateInstanceChanged` y por la lectura inicial en el Job.

## Endpoints (`/api/v1`, `auth:sanctum` + `ensure.api.permission`)

| Método | Ruta | Permiso | Idempotency-Key | Respuesta |
|---|---|---|---|---|
| `GET` | `/instances` | `instancias.ver` | no | lista paginada del acting tenant (`InstanceResource`) |
| `POST` | `/instances` | `instancias.crear` | sí | **202 Accepted**, `status=provisioning` |
| `GET` | `/instances/{publicId}` | `instancias.ver` | no | estado + `greenState` |
| `GET` | `/instances/{publicId}/qr` | `instancias.ver` | no | QR base64 si `waiting_qr`; **409** si `authorized` |
| `DELETE` | `/instances/{publicId}` | `instancias.eliminar` | sí | dispara teardown, soft-delete; 202 |

### Convenciones

- **202 en POST** (no 201) porque la creación es asíncrona. waapi hace polling a `GET /instances/{publicId}` hasta `status=authorized`.
- **Idempotencia doble:** header `Idempotency-Key` (middleware `api.idempotency` existente) + `external_ref` único por tenant. Re-POST con el mismo `external_ref` devuelve el registro existente (200) sin crear duplicado ni re-despachar Job, igual que `/tenants`.
- **Acting tenant:** waapi usa el token de plataforma + `X-Tenant-Id: {publicId del tenant demo}`; `ResolveActingTenant` (ya existente) resuelve el tenant. Usuarios no-plataforma quedan confinados a su `tenant_id`.
- **Guard de módulo:** el endpoint verifica que el módulo `whatsapp` esté habilitado en `core_modules` para el acting tenant; si no, `403`.
- **Aislamiento:** todas las consultas scoped por `tenant_id` del acting tenant (Global scope vía `BelongsToTenant`).

### Body `POST /instances`

```json
{
  "label": "Demo Acme",
  "externalRef": "waapi_demo_42",
  "purpose": "demo"
}
```

| Campo | Tipo | Reglas |
|---|---|---|
| `label` | string | requerido, max 255 |
| `externalRef` | string | opcional, único por tenant; clave de idempotencia waapi |
| `purpose` | string | opcional, `in:demo,production`, default `demo` |

### `InstanceResource` (camelCase)

```json
{
  "publicId": "01JXYZ...",
  "label": "Demo Acme",
  "purpose": "demo",
  "status": "waiting_qr",
  "greenState": "notAuthorized",
  "idInstance": "1101234567",
  "authorizedAt": null,
  "createdAt": "2026-06-29T12:00:00+00:00",
  "updatedAt": "2026-06-29T12:00:05+00:00"
}
```

**`apiTokenInstance` nunca se serializa.** `idInstance` se expone (no es secreto sin el token).

### `GET /instances/{publicId}/qr`

```json
{ "qr": "data:image/png;base64,iVBORw0...", "expiresAt": "2026-06-29T12:01:00+00:00" }
```

- Si `status=authorized` → `409 Conflict` (`{"message":"Instance already authorized"}`).
- Si `status` aún `provisioning`/`configuring` → `409` con mensaje "instance not ready".
- El QR se obtiene on-demand vía `InstanceClient::qr()` y se cachea en `qr_code`/`qr_expires_at` (TTL corto, p. ej. 20s) para no martillar a Green.

## Componentes de código

| Componente | Ruta | Responsabilidad |
|---|---|---|
| `PartnerClient` | `app/Services/GreenApi/PartnerClient.php` | Wrapper HTTP de Partner: `createInstance`, `deleteInstanceAccount`, `getInstances`. Usa `GREEN_API_PARTNER_TOKEN`. Lanza `GreenApiException` en no-2xx. |
| `InstanceClient` | `app/Services/GreenApi/InstanceClient.php` | Por-instancia: `getStateInstance`, `qr`, `setSettings`, `logout`, `reboot`. Construido con `idInstance` + token. |
| `InstanceProvisioningService` | `app/Services/GreenApi/InstanceProvisioningService.php` | Crea registro local idempotente (por `external_ref`), aplica transiciones de estado, descifra token para los clients. |
| `ProvisionGreenInstanceJob` | `app/Jobs/ProvisionGreenInstanceJob.php` | Horizon, cola `provisioning`. Idempotente. Llama `PartnerClient::createInstance` → persiste `id_instance` + token cifrado → `InstanceClient::setSettings(webhook)` → lee estado/QR inicial → actualiza `status`. Reintentos con backoff + `RateLimited` (Redis throttle). Al agotar → `status=failed` + `last_error` + `log_bitacora`. |
| `DeleteGreenInstanceJob` | `app/Jobs/DeleteGreenInstanceJob.php` | Teardown: `PartnerClient::deleteInstanceAccount` → soft-delete local. |
| `InstanceController` | `app/Http/Controllers/Api/V1/InstanceController.php` | index/store/show/qr/destroy. |
| `InstanceResource` | `app/Http/Resources/Api/V1/InstanceResource.php` | Serialización camelCase sin token. |
| Form Requests | `app/Http/Requests/Api/V1/StoreInstanceRequest.php` | Validación del body. |
| `Instancia` (modelo) | `app/Models/Integration/Instancia.php` | `BelongsToTenant`, `public_id` ULID, cast `encrypted` del token, `getRouteKeyName` = `public_id`. |
| Webhook routing | `app/Http/Controllers/Api/V1/IncomingWebhookController.php` (extensión) | Enruta `typeWebhook=stateInstanceChanged` → busca instancia por `idInstance` → actualiza `green_state`/`status`/`authorized_at`. Mensajes entrantes resuelven tenant por `idInstance`. |
| Config | `config/services.php` bloque `green_api` | `base_url`, `partner_token`, `webhook_url`, `webhook_secret`. |
| Permisos | seeder (`RolesAndPermissionsSeeder`/`CoreSeeder`) + `config/permissions.php` | `instancias.ver`, `instancias.crear`, `instancias.eliminar`. Asignados a cuenta de plataforma y rol admin. |

### Configuración (env)

```env
GREEN_API_BASE_URL=https://api.green-api.com   # ya existe
GREEN_API_PARTNER_TOKEN=                        # NUEVO — token Partner
GREEN_API_WEBHOOK_URL=https://api.lebytek.com/api/v1/webhooks/incoming  # NUEVO
WEBHOOK_SECRET=                                 # ya existe — usado como webhookUrlToken
```

`config/services.php`:

```php
'green_api' => [
    'base_url'      => env('GREEN_API_BASE_URL', 'https://api.green-api.com'),
    'partner_token' => env('GREEN_API_PARTNER_TOKEN'),
    'webhook_url'   => env('GREEN_API_WEBHOOK_URL'),
    'webhook_secret'=> env('WEBHOOK_SECRET'),
],
```

## Flujo de provisioning (secuencia)

```
waapi ──POST /instances (X-Tenant-Id, Idempotency-Key, externalRef)──► api
api: crea int_instancias (status=provisioning) + dispatch ProvisionGreenInstanceJob
api ──202 Accepted {publicId, status:provisioning}──► waapi

[Job en Horizon]
  PartnerClient::createInstance(partnerToken)        → {idInstance, apiTokenInstance}
  persistir id_instance + api_token_instance (cifrado), status=configuring
  InstanceClient::setSettings(webhookUrl, webhookUrlToken, incomingWebhook=yes, stateWebhook=yes)
  InstanceClient::getStateInstance()                 → green_state
  status = (green_state==authorized) ? authorized : waiting_qr

[Polling waapi]  GET /instances/{publicId} → status

[Cliente escanea QR]  GET /instances/{publicId}/qr → base64
Green ──webhook stateInstanceChanged (authorized)──► api /webhooks/incoming
api: instancia.green_state=authorized, status=authorized, authorized_at=now
```

## Errores

- `GreenApiException` tipada para fallos de Green (no-2xx, payload inesperado).
- Job: 3 intentos, backoff (10s/30s/60s). Al agotar → `status=failed`, `last_error` con detalle, entrada en `log_bitacora`.
- waapi observa `status=failed` por `GET` y puede reintentar con un nuevo POST (nuevo `external_ref`).
- HTTP: 401 token ausente/ inválido, 403 sin permiso RBAC o módulo `whatsapp` deshabilitado, 404 instancia/tenant inexistente, 409 QR no aplicable, 422 validación, 429 rate limit.

## Pruebas (Pest, `Http::fake` + `Bus::fake`)

- **POST**: crea registro, despacha Job, responde 202; idempotente por `external_ref` (no duplica ni re-despacha).
- **Job**: con `Http::fake` de Partner + instancia, persiste token **cifrado**, llama `setSettings` con el `webhookUrl`/token correctos, transiciona `provisioning→configuring→waiting_qr`; en fallo persistente marca `failed` + `last_error`.
- **Webhook**: `stateInstanceChanged=authorized` voltea la instancia a `authorized` + `authorized_at`.
- **QR**: 200 con base64 en `waiting_qr`; 409 en `authorized`.
- **RBAC default-deny**: sin permiso → 403; instancia de otro tenant → 404/aislada.
- **Guard de módulo**: tenant sin `whatsapp` habilitado → 403.

## Fuera de alcance (YAGNI)

- Expiración automática de instancias demo.
- Topes de mensajes / cuotas forzadas.
- Facturación / medición monetaria. (Solo se deja el hook de contador de uso, a poblar cuando exista el envío de mensajes en Fase 2.)
- Endpoints de campañas/mensajes (Fase 2 posterior).

## Referencias

- Contrato API: `docs/integration/waapi-api-contract.md` (sección Fase 2).
- Spec núcleo: `docs/spec/prompt2-laravel-nucleo.md` (throttling Redis, medición de uso por tenant).
- Patrón Fase 1: `app/Services/TenantProvisioningService.php`, `app/Http/Controllers/Api/V1/TenantController.php`.
