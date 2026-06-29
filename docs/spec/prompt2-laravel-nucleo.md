# Prompt — Núcleo administrativo Laravel + Inertia + Vue (reutilizable)

Actúa como arquitecto de software senior especializado en **Laravel 11, PHP 8.2+, MySQL, Inertia.js, Vue 3 y Tailwind**. Diseña y construye la **base reutilizable** de una plataforma administrativa modular, lista para producción y para montar verticales de negocio encima.

---

## Objetivo

Construir un **núcleo administrativo reutilizable** (no un producto final) con:
autenticación, RBAC por roles/permisos, menú de administración dinámico, dashboard extensible por widgets, bitácora de auditoría y configuración general.

El núcleo debe permitir agregar **módulos de dominio (verticales)** sin tocar el núcleo. No implementes ningún dominio de negocio en esta entrega: solo la base.

## Contexto / producto objetivo (solo para justificar decisiones de arquitectura)

El primer vertical que se montará sobre este núcleo es una **API de WhatsApp** que consume una **API de terceros (Green API)** y agrega una **capa propia de control de colas y campañas masivas**. Por eso el núcleo debe nacer preparado para:

- **Colas robustas** (envío asíncrono, reintentos, rate-limiting, backoff).
- **Jobs en background** observables y reintentables.
- **Procesos masivos** (campañas que despachan miles de mensajes).

No construyas el vertical de WhatsApp. Solo deja el núcleo listo para alojarlo.

---

## Stack obligatorio

- **Laravel 11**, PHP 8.2+
- **MySQL**
- **Inertia.js + Vue 3** (`<script setup>`)
- **Tailwind CSS**
- **Laravel Breeze** con preset **Inertia + Vue** como scaffolding inicial de auth/layout
- **Vite** para assets
- **Queues + Laravel Horizon** sobre **Redis** (para el control de colas del producto)
- **Redis** como driver de **cache, sessions, queue y rate-limiting**
- **spatie/laravel-permission** para RBAC
- **Laravel Sanctum** para auth por token de la API pública
- **Object storage S3-compatible** como disco de uploads (no disco local)
- Configuración sensible mediante **.env**; secretos en DB **cifrados** (encrypted casts)

---

## Decisiones de arquitectura ya tomadas (respétalas, no las cambies)

### Filosofía: **Laravel-first**
Usa lo nativo de Laravel siempre que exista. Construye a medida **solo** lo que el framework no da. No reinventes lo que el ecosistema ya resuelve bien.

### Qué usar de Laravel vs. qué construir a medida

| Necesidad | Solución |
|---|---|
| Auth web (admin), sesiones, login, password reset | **Breeze nativo** (preset Inertia-Vue) |
| Auth API (clientes programáticos) | **Sanctum** (personal access tokens), guard separado |
| RBAC (roles, permisos slug `modulo.accion`) | **spatie/laravel-permission** (tablas nativas `roles`/`permissions`) + Policies/Gates, **default-deny** |
| Secretos/credenciales de terceros en DB | **Encrypted casts** de Laravel (nunca texto plano) |
| Rate limiting | **RateLimiter** nativo (web + API) y **Redis throttle** en jobs |
| ORM y acceso a datos | **Eloquent** directo (sin capa Repository extra) |
| Validación de entrada | **Form Requests** |
| Colas, jobs, reintentos, scheduling | **Queues nativas + Horizon** |
| Eventos / hooks | **Eventos y Listeners** nativos |
| Subida/almacenamiento de archivos | **Storage** nativo (`core_archivos` como metadata) |
| **Menú admin dinámico** | **A medida** (tabla `core_menu_items`, filtrado por permisos del usuario, compartido a Inertia vía middleware) |
| **Dashboard extensible por widgets** | **A medida** (contrato `DashboardWidget`, providers registrados en config) |
| **Bitácora / auditoría** | **A medida** (tabla `log_bitacora`) |
| **Configuración general** | **A medida** (tabla `cfg_configuraciones`, key-value tipado) |

### Qué queda EXPLÍCITAMENTE descartado o delegado

- **NO** uses **Filament**. Filament es Livewire/Blade y choca con el stack Inertia+Vue elegido. Mantén un solo stack frontend coherente.
- **NO** construyas un "CRUD Engine" genérico por configuración. En Laravel cada recurso se implementa con un **controller Eloquent normal + páginas Inertia/Vue**. Reinventar un motor CRUD genérico es anti-idiomático aquí.
- **PDF:** si un vertical necesita PDF, se usará un paquete del ecosistema (**`spatie/laravel-pdf`** o **`barryvdh/laravel-dompdf`**). No construyas un "pdf-kit" propio.
- **Calendario:** si un vertical necesita vista calendario, se usará **FullCalendar** (componente Vue) leyendo de un recurso Eloquent. No construyas un motor de calendario propio.
- **Reportes/charts:** widgets de dashboard a medida + **`vue-chartjs`** para gráficas. No hay paquete propio de reportes en el núcleo.

---

## Arquitectura de carpetas

Usa la estructura **idiomática de Laravel** (no Onion estricta). Cuando un vertical tenga lógica de negocio real, agrúpala en `app/Domain/{Modulo}/` (services, DTOs, actions), pero **Controllers, Models, Jobs, Events, Policies viven en su lugar Laravel estándar**. Las páginas Vue viven en `resources/js/Pages/`, los componentes compartidos en `resources/js/Components/`, layouts en `resources/js/Layouts/`.

Regla de dependencia ligera: el código de dominio (`app/Domain/*`) no debe depender de la capa HTTP/Inertia. Los Controllers orquestan; el dominio decide.

---

## Convención de base de datos (prefijos solo para lo propio) — REGLA CENTRAL

**Principio:** los prefijos identifican **lo que TÚ creas**. No se modifica ni renombra nada de terceros "por capricho". Si un paquete nombra sus tablas de cierta forma, **se respetan tal cual** para que otros programadores y la IA las interpreten por su convención conocida.

### 1. Tablas de terceros → nombre nativo del paquete (NO tocar)

Se quedan **exactamente** como las publica el paquete:

- **Infra Laravel:** `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`, `password_reset_tokens`, `migrations`.
- **Breeze / Auth:** `users`, `personal_access_tokens`.
- **spatie/laravel-permission:** `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`.

No renombres estas tablas. No publiques migraciones de spatie solo para prefijarlas.

### 2. Tablas que TÚ creas (plataforma + dominio) → prefijo por módulo

- `cfg_*` — configuración del sistema (layout, colores, branding, PWA…)
- `log_*` — auditoría y bitácora
- `core_*` — metadatos de plataforma (menú, módulos, archivos)
- `int_*` — integraciones (webhooks, credenciales de terceros)
- `rep_*` — reportes / métricas
- `tmp_*` — trabajos/colas **de dominio** (NO confundir con `jobs` de Laravel)
- `sys_*` — key-value y utilidades del sistema
- `auth_*` — **solo** si creas tablas de auth propias que spatie/Breeze no cubren
- `dom_*` — **reservado** para tablas de verticales de negocio (nunca en el núcleo)

**Cómo implementarlo:**
- Cada modelo de tabla propia declara su tabla explícitamente: `protected $table = 'cfg_configuraciones';`
- Cada migración propia crea la tabla con el nombre prefijado.
- **NO** uses el `prefix` global de la conexión de base de datos: aplicaría un mismo prefijo a TODO (incluido lo de terceros) e impide la convención por módulo.

**Tablas propias del núcleo a crear:**

| Concepto | Tabla |
|---|---|
| Tenants (clientes) | `core_tenants` |
| Configuración (layout, colores, branding, PWA) | `cfg_configuraciones` |
| Catálogos auxiliares | `cfg_catalogos_auxiliares` |
| Bitácora | `log_bitacora` |
| Módulos de plataforma | `core_modules` |
| Ítems de menú | `core_menu_items` |
| Archivos (metadata: logo, íconos, favicon) | `core_archivos` |
| Credenciales de terceros (cifradas) | `int_credenciales` |
| Key-value sistema | `sys_kv` |

**Fuente de verdad de módulos:** `core_modules` (DB) es la autoritativa del estado on/off por tenant. Un `config/vertical.php` solo declara los módulos *disponibles* en el código; el toggle efectivo vive en `core_modules`. No dupliques el estado en dos lugares.

Usuarios, roles y permisos **no** se crean a mano: vienen de Breeze (`users`) y spatie (`roles`, `permissions`). El RBAC se modela sobre esas tablas nativas.

---

## RBAC

- Permisos en formato slug `modulo.accion` (ej.: `dashboard.ver`, `usuarios.gestionar`, `campanias.crear`).
- Roles agrupan permisos. Se aplican con **Gates/Policies** y un **middleware** de permiso en rutas.
- El menú dinámico (`core_menu_items`) se filtra por los permisos del usuario **al renderizar**, compartido a Inertia (`HandleInertiaRequests`).
- El RBAC se modela sobre las tablas nativas de spatie (`roles`, `permissions`), no sobre tablas `auth_*` propias.
- Seeder inicial: rol **admin** con todos los permisos del núcleo + usuario admin por defecto en `users` (`admin@sistema.local`, exigir cambio de contraseña antes de producción).

---

## Multi-tenancy (desde el núcleo)

El producto es multi-cliente. Diseña el aislamiento de datos **ahora**, no después:

- Estrategia **single-database con `tenant_id`** + **Global Scopes** de Eloquent que filtran automáticamente por tenant en toda consulta.
- Toda tabla de datos propia/dominio lleva `tenant_id` (FK a `core_tenants`); el núcleo resuelve el tenant del usuario/token autenticado y lo inyecta en un contexto de request.
- Trait/base model reutilizable (`BelongsToTenant`) que aplica el scope y asigna `tenant_id` al crear.
- El usuario admin de plataforma puede operar cross-tenant; los usuarios de cliente quedan confinados a su tenant.
- Tabla `core_tenants` (propia). Los `users` (Breeze) referencian su `tenant_id`.

## API pública y separación de auth

- **Dos caminos de auth claramente separados:**
  - `routes/web.php` → sesión + CSRF (Inertia, panel admin).
  - `routes/api.php` bajo prefijo **`/api/v1`** → **Sanctum token**, stateless, sin CSRF, con throttling por token/tenant.
- Versiona la API desde el inicio (`/api/v1/...`). Nunca rutas de API sin versión.
- **Postura default-deny:** todo endpoint declara su permiso (`can:` middleware). Un endpoint sin permiso declarado debe fallar/cerrarse, no quedar abierto. Incluye un test que lo verifique.
- Respuestas de API vía **API Resources** (nunca volcar modelos Eloquent crudos).

## Seguridad reforzada (obligatoria en el núcleo)

- **Uploads (logo/favicon/ícono PWA):** validar **mimetype real por contenido** (no por extensión), allowlist estricta, **prohibir SVG** (o sanitizarlo), **re-encodear** la imagen con GD/Imagick para destruir payloads embebidos, nombres aleatorios, servir vía controller, almacenar fuera del webroot en object storage.
- **Secretos en DB cifrados:** credenciales de terceros (`int_*`) con encrypted casts. Nunca texto plano.
- **Webhooks entrantes:** middleware de **verificación de firma/HMAC** obligatorio + **idempotencia por event-id** (dedupe). Nada de confiar en el payload sin verificar.
- **Bitácora (`log_bitacora`):** append-only, registra actor/IP/User-Agent/antes-después. **No registrar PII ni contenido de mensajes en claro.**
- **Mass assignment:** `$fillable` explícito en todos los modelos; nunca `$guarded = []`. Solo datos validados por Form Request.
- **Auth hardening:** throttling de login nativo, cookies `secure`+`SameSite`, política de contraseñas, y soporte opcional 2FA (Fortify) previsto.

## Escalabilidad (obligatoria en el núcleo)

- **Colas separadas por prioridad:** cola transaccional vs cola de campañas, con **workers aislados en Horizon** para que una campaña masiva no bloquee envíos transaccionales.
- **Dispatch por chunks + `Bus::batch`:** nunca cargar toda la lista de destinatarios en memoria; procesar por lotes.
- **Throttling Redis** (`Redis::throttle` / job middleware `RateLimited`) para respetar límites del proveedor (Green API).
- **Idempotencia** por clave de mensaje para evitar doble-envío en reintentos; retry con backoff + manejo de `failed_jobs`/dead-letter.
- **Cache (Redis) de configuración y menú:** `cfg_configuraciones` y `core_menu_items` se cachean (menú por rol) e invalidan al escribir. No leer DB por cada render.
- **Manifest/favicon dinámicos cacheados** y versionados por hash, con cache headers largos y cache-busting al cambiar.
- **Datos de alto volumen:** índices adecuados, enums de estado, **retención/archivado** de logs de mensajes, y **paginación obligatoria** en todo listado (nada de queries sin límite). Disciplina de **eager loading** para evitar N+1.
- **App servers stateless:** uploads en object storage; cache/session/queue/rate-limit en Redis.

## Alcance de esta entrega

Por ahora el foco es **dejar el núcleo configurado**: sus reglas, su plumbing, sus contratos y todo lo que se montará encima. La **interfaz generada es mínima** (ver más abajo). No generes CRUDs de ejemplo ni pantallas de gestión de usuarios/roles más allá de lo necesario para que el núcleo funcione.

### A) Plumbing del núcleo — dejar configurado y listo (no como pantallas)

1. **Auth + RBAC** (Breeze + spatie) operativo.
2. **Menú admin dinámico** (`core_menu_items`) filtrado por permisos, compartido a Inertia. Layout configurable (top/side).
3. **Dashboard extensible** por widgets: contrato `DashboardWidget` + registro en config + agregación. Deja el contrato y el mecanismo listos (no hace falta poblarlo de widgets).
4. **Bitácora** (`log_bitacora`): servicio listo para registrar acciones.
5. **Toggle de módulos** con estado autoritativo en `core_modules` (DB) por tenant; `config/vertical.php` solo declara disponibilidad en código.
6. **Colas + Horizon** configuradas para el vertical WhatsApp (sin implementar el vertical).

### B) Pantallas que SÍ se generan (interfaz mínima)

1. **Index público** (`/`) para clientes: landing simple, instalable como PWA, con branding configurable (logo).
2. **Route `/admin`** con **login** (Breeze Inertia-Vue).
3. **Panel admin** (tras login) que contiene **únicamente**:
   - **Configuración de layout**: disposición del menú (top/side) y opciones de layout de la app.
   - **Configuración de colores/tema**: paleta/colores aplicables al front (tokens Tailwind / CSS variables).
   - **Configuración de branding y PWA**: logo (login + sidebar), **favicon** e **ícono PWA**; nombre de la app.

Todo lo de configuración persiste en `cfg_configuraciones` (y los archivos de imagen en `core_archivos` + Storage), y se aplica en tiempo de render.

## PWA y branding configurable

- La aplicación es una **PWA instalable**: `manifest.webmanifest` + service worker (registro de assets, offline básico del shell, prompt de instalación).
- **El `manifest` se genera dinámicamente** desde la configuración: nombre de la app, colores (`theme_color`, `background_color`) e **ícono de la PWA** salen de `cfg_configuraciones` / `core_archivos`, no hardcodeados.
- **Favicon configurable**: servido desde la configuración, no estático en `public/`.
- **Logo configurable**: el mismo recurso de logo se inyecta en login, sidebar y donde aplique, leyendo de configuración.
- El admin sube estas imágenes desde la pantalla de branding aplicando las **reglas de uploads seguros** (validación por contenido, sin SVG, re-encode, object storage) y se versionan por hash para invalidar caché.

---

## Calidad y deuda técnica (evitar a futuro)

Reglas obligatorias para que el núcleo no acumule deuda al crecer por verticales:

- **Tests + CI desde el inicio:** suite con **Pest/PHPUnit**, **factories** para todos los modelos del núcleo, y pipeline CI. Como mínimo: feature tests de auth (web y Sanctum), de RBAC default-deny, y **un test que pruebe el aislamiento entre tenants** (tenant A no puede leer/escribir datos del tenant B). Sin ese test, el multi-tenancy no se considera entregado.
- **Identificadores públicos con ULID:** las claves expuestas en API/URLs usan **ULID** como route key (no exponer IDs autoincrementales: filtran volumen y permiten enumeración). El `bigint` autoincremental queda como PK interna.
- **Configuración tipada, no bolsa de strings:** `cfg_configuraciones` se accede mediante un **contrato/registro de claves tipado** con validación y valores por defecto por clave. Prohibido leer claves mágicas sueltas dispersas por el código.
- **Soft deletes + cascada de tenant:** soft deletes en entidades relevantes; estrategia explícita de qué pasa al **eliminar un tenant** (cascada/anonimización de todos sus datos `tenant_id`). Nada de borrados huérfanos.
- **Observabilidad:** integración de **error tracking** (Sentry/Flare), **logging estructurado**, endpoint de **health-check**, y alertas de profundidad/fallo de cola (sobre Horizon).
- **Contrato de API documentado:** generar **OpenAPI** (Scribe o similar) para la API pública desde el día uno; es producto de cara al cliente.
- **Medición de uso por tenant:** dejar un hook/contador de **uso y cuota por tenant** (las campañas cuestan dinero en Green API): base para límites y facturación futura, aunque no se facture aún.
- **Idempotency-Key en endpoints de escritura de la API** (no solo en jobs): clientes que reintentan un POST de campaña no deben duplicar.
- **Frontend mantenible:** **TypeScript** en Vue, **tokens de tema** (CSS variables) definidos como contrato para que la configuración de colores no se reinvente por vertical, y **localización (i18n)** desde el inicio (sin strings hardcodeados en UI).

## Convenciones de nombres

- **Clases PHP:** PascalCase; métodos camelCase; constantes UPPER_SNAKE_CASE.
- **Tablas/columnas:** snake_case plural; claves foráneas `tabla_id`.
- **Rutas API:** `/api/v1/recurso` (siempre versionado); claves JSON en camelCase.
- **Componentes Vue:** PascalCase; páginas Inertia bajo `resources/js/Pages/{Modulo}/`.
- **Permisos:** `modulo.accion`.

---

## Reglas de implementación (qué NO hacer)

- No mezcles lógica de negocio en componentes Vue ni en Controllers gordos.
- No metas SQL crudo en vistas; usa Eloquent.
- No reinventes auth, colas, validación ni RBAC: ya existen en el stack.
- No introduzcas Filament ni un CRUD engine genérico.
- No uses nombres ambiguos. Propón convenciones claras desde el inicio.
- Prioriza claridad sobre "magia". Todo preparado para crecer por verticales sin tocar el núcleo.
- Seguridad base obligatoria: CSRF (nativo Inertia), hashing, validación con Form Requests, autorización con Policies, rate-limiting en endpoints sensibles.

---

## Entrega en este orden

1. **Estructura de carpetas completa** (backend Laravel + `resources/js` Inertia/Vue).
2. **Convención de nombres** (archivos, clases, tablas, permisos, componentes).
3. **Política de base de datos** con la regla de prefijos solo-para-lo-propio aplicada (terceros con nombre nativo).
4. **Migraciones** del núcleo: solo tablas propias (`core_tenants`, `cfg_*`, `log_*`, `core_*`, `int_*`, `sys_*`), reversibles y seguras. Las de Breeze/spatie/infra quedan con sus migraciones nativas.
5. **Seeders** (tenant inicial, usuario admin en `users`, rol admin y permisos del núcleo vía spatie, ítems de menú).
6. **Multi-tenancy**: `core_tenants`, trait `BelongsToTenant`, Global Scopes, resolución de tenant por request.
7. **Auth web + API**: Breeze (sesión/CSRF) y Sanctum (`/api/v1`, token, default-deny); guards y middleware de RBAC; route `/admin` protegida.
8. **Menú dinámico**: modelo, migración, servicio de armado (cacheado por rol), share a Inertia, componente Vue.
9. **Dashboard extensible**: contrato `DashboardWidget` y mecanismo de registro/agregación listos (sin poblar widgets).
10. **Configuración del núcleo**: modelo/migración `cfg_configuraciones` + `core_archivos`, servicio de lectura/escritura **cacheado (Redis)** con invalidación, share a Inertia para aplicar layout/colores/branding en render.
11. **PWA**: manifest dinámico desde configuración (cacheado/versionado), service worker, favicon/ícono/logo configurables.
12. **Uploads seguros**: validación por contenido, prohibición/saneo de SVG, re-encode, object storage, servido vía controller.
13. **Webhooks**: middleware de verificación de firma/HMAC + idempotencia (base para `int_webhooks`).
14. **Colas escalables + Horizon**: colas por prioridad, throttling Redis, `Bus::batch`/chunking e idempotencia, listas para el vertical WhatsApp (sin implementarlo).
15. **Pantallas generadas** (alcance mínimo): index público instalable + login + panel admin con las 3 pantallas de configuración (layout, colores, branding/PWA).
16. **Bitácora y seguridad base**: `log_bitacora` append-only (sin PII), default-deny, `$fillable` explícito, rate limiting web+API.
17. **Tests + CI**: Pest/PHPUnit, factories, feature tests de auth/RBAC y **test de aislamiento entre tenants**; pipeline CI.
18. **Observabilidad y API docs**: error tracking, logging estructurado, health-check, alertas de cola; OpenAPI de la API pública.
19. **README**: "cómo agregar un vertical de dominio" (tablas `dom_*` con `tenant_id`, modelos con `BelongsToTenant`, ULID público, controllers Eloquent, API Resources, permisos, menú, toggle en `core_modules`).

---

## Importante

No generes nada superficial. Quiero una base **seria, idiomática de Laravel, con un solo stack frontend (Inertia+Vue+Tailwind)**, lista para convertirse en una plataforma modular real y para alojar el vertical de WhatsApp (Green API) con su capa de colas y campañas masivas. Respeta todas las decisiones de arquitectura ya tomadas en este documento.
