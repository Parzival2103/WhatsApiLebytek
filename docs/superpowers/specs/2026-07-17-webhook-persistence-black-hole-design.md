# Webhook Persistence — Closing the Black Hole (Design)

**Fecha:** 2026-07-17
**Estado:** Diseño aprobado (pendiente revisión de spec por el usuario)
**Repo:** `Parzival2103/WhatsApiLebytek` (`api.lebytek.com`)

## Problema

El endpoint `POST /api/v1/webhooks/incoming` **descarta silenciosamente** todos los
eventos de Green API excepto `stateInstanceChanged`. Peor aún, el descarte se
*confirma* como procesado, de modo que los reintentos de Green quedan bloqueados.

Evidencia en el código actual:

- `IncomingWebhookController::__invoke()` solo ramifica en `stateInstanceChanged`;
  cualquier otro `typeWebhook` (`incomingMessageReceived`, `outgoingMessageStatus`,
  `outgoingAPIMessageReceived`, `incomingCall`, …) cae a un `200 { received: true }`
  sin persistir nada.
- La tabla `int_webhooks` existe desde `2026_07_02_100001` pero **no tiene ningún
  escritor** en toda la base de código (solo migración, un test de existencia y docs).
- `WebhookIdempotency` (middleware) cachea el `event_id` como procesado cuando la
  respuesta es `2xx`. Un evento descartado **es** una respuesta exitosa, así que se
  cachea por 24h y el reintento de Green vuelve como `duplicate: true` y se descarta
  de nuevo.

### Por qué importa ahora

Antes de PR #20 los webhooks reales de Green se rechazaban en **401** (faltaba firma
HMAC), así que nada entraba y nada se perdía. Ese merge arregló la autenticación
(dual HMAC/Bearer). Desde entonces, tráfico real de Green **sí** llega al controller
por primera vez, se descarta, y se cachea como procesado. El agujero solo se volvió
crítico al arreglar la puerta. Todos los specs previos que difirieron `int_webhooks`
("fuera de alcance", P2, P3) se escribieron cuando el endpoint estaba efectivamente
muerto; ese razonamiento expiró con PR #20.

### Consecuencias concretas observadas en el código

1. **Instancia atascada en `waiting_qr`.** En `handleStateInstanceChanged`, si
   `stateInstanceChanged: authorized` llega antes de que `ProvisionGreenInstanceJob`
   haya committeado `id_instance`, el `return` por "instancia no encontrada" produce
   un `200`, se cachea, y el reintento de Green se traga. El usuario escaneó el QR
   pero el panel dice "esperando" para siempre.
2. **`int_mensajes.status` nunca avanza más allá de `sent`.** Solo
   `outgoingMessageStatus` podría moverlo a `delivered`/`read` o a un `failed`
   post-envío, y ese webhook se descarta. `UsageController` cuenta envíos sin
   verificación de entrega.

## Alcance

### Dentro de alcance (versión pequeña — "cerrar el agujero")

- **Persistir todo evento de webhook válido en `int_webhooks` antes de procesarlo.**
- **Mover la deduplicación al índice único `event_id` de la BD** (fuente de verdad),
  con la caché Redis degradada a fast-path opcional (no autoridad).
- **Procesamiento uniforme asíncrono:** todo tipo de evento se maneja en un job,
  incluido `stateInstanceChanged` (que hoy es inline).
- **Watcher de salud:** un comando programado que alerta si hay filas con
  `processed_at IS NULL` acumulándose (detección de fallos de procesamiento *y* de
  pérdida de eventos).
- **Eliminar `PUT /credentials/green-api`** (stub 501 nunca implementado) y su
  permiso `credenciales.gestionar` — decidido fuera del núcleo del agujero pero
  incluido aquí para no perderlo (ver "Limpieza asociada").

### Fuera de alcance (versión grande — feature separada, otro spec)

- Convertir `outgoingMessageStatus` en actualizaciones de `int_mensajes.status`
  (delivered/read/failed post-envío).
- Convertir `incomingMessageReceived` en filas `int_mensajes` (direction=inbound).
- Nuevos tipos de webhook con lógica de negocio propia.

En la versión pequeña, esos eventos **se persisten** en `int_webhooks` (no se pierde
nada) pero **no** se traducen todavía a cambios de dominio. Eso es lo que hace este
spec shippable por sí solo.

## Decisión de arquitectura: Modelo B (somos nuestro propio motor de reintentos)

Se descartó el **Modelo A** (Green es el motor de reintentos: devolver `5xx` para
forzar redelivery, no persistir nada) por dos razones:

1. **Sin evidencia.** Si Green agota sus reintentos, el evento desaparece sin rastro
   en nuestra BD. Depurar "un cliente dice que nos escribió y nunca lo vimos" sin
   registro es la misma ceguera de hoy.
2. **Semántica de error falsa.** Un `authorized` para una instancia aún no
   committeada es un evento *válido*, no un error. Forzar retry requeriría devolver
   `500` para payloads correctos.

**Modelo B** resuelve ambos: la fila persistida con `processed_at IS NULL` es un
work-item reintentables en *nuestro* horario. La carrera de `id_instance` se resuelve
sola cuando el job reintenta y la instancia ya existe — sin semántica de error.

El esquema de `int_webhooks` ya está diseñado para esto: `event_id` único,
`tenant_id` nullable (aún no resuelto al insertar), `processed_at` nullable (fila
puede existir sin procesar). No se diseña nada nuevo; se termina lo que la migración
ya describe.

## Flujo

```
POST /api/v1/webhooks/incoming
  → VerifyWebhookSignature  (HMAC o Bearer; sin cambios)
  → controller:
      1. resolveEventId(request)         // cascada existente, se conserva
      2. INSERT int_webhooks {event_id, type_webhook, id_instance, payload}
           ├─ violación de único (event_id) → duplicado: ack 200 {duplicate:true}, fin
           └─ ok → continúa
      3. dispatch ProcessWebhookJob(webhook.id)
      4. ack 200 {received:true, duplicate:false}       // ya persistido

ProcessWebhookJob (cola):
      - carga fila int_webhooks
      - resuelve tenant_id por id_instance (si aplica) y lo guarda
      - ramifica por type_webhook:
          stateInstanceChanged → lógica actual (green_state/status/authorized_at)
          otros                → persist-only (no-op de dominio en v1)
      - stamp processed_at = now()
```

### Deduplicación

- **Autoridad:** índice `int_webhooks.event_id UNIQUE`. Transaccional con la
  escritura; sobrevive a un flush de Redis; "¿lo vi?" y "¿lo guardé?" son la misma
  pregunta.
- **Fast-path opcional:** la caché Redis puede seguir como salto barato *antes* de
  tocar la BD, pero si está fría el índice único igual atrapa el duplicado. Ya no es
  fuente de verdad.
- **`resolveEventId`:** la lógica actual del middleware (`idMessage` → `idWebhook` →
  composite `typeWebhook|idInstance|timestamp|…` → `sha1(body)`) es correcta y se
  conserva; solo cambia el consumidor (BD en vez de caché).

### Decisión clave: ack-on-persist

Se responde `200` **en cuanto la fila se persiste**, no cuando se procesa. Green no
controla nuestros reintentos; nosotros sí, vía `processed_at IS NULL`. Consecuencia
aceptada: un bug de procesamiento ya **no** se manifiesta como error HTTP, sino como
filas sin procesar — de ahí el watcher (abajo), que es obligatorio en este modelo.

## Componentes

| Componente | Acción | Responsabilidad |
|---|---|---|
| `App\Models\Integration\Webhook` (nuevo) | Crear | Modelo Eloquent sobre `int_webhooks` |
| `IncomingWebhookController` | Modificar | resolveEventId → insert → dispatch → ack; ya no procesa inline |
| `ProcessWebhookJob` (nuevo) | Crear | Resuelve tenant, ramifica por tipo, stamp `processed_at`. Sigue patrón de `TransactionalMessageJob` (`ShouldQueue`, `$tries`, `onQueue`, re-fetch `withoutGlobalScope('tenant')`) |
| `WebhookIdempotency` (middleware) | Modificar o retirar | Degradar a fast-path; la unicidad real vive en BD. Mover `resolveEventId` a un servicio/helper compartido si el controller lo necesita |
| Comando `webhooks:check-unprocessed` (nuevo) | Crear | Cuenta filas `processed_at IS NULL` más antiguas que un umbral; loguea/alerta |
| `routes/console.php` | Modificar | Programar el comando (hoy no hay scheduler configurado) |

### Watcher de eventos sin procesar

- Un comando Artisan que cuenta `int_webhooks WHERE processed_at IS NULL AND
  created_at < now()-Xmin`.
- Programado en `routes/console.php` (Laravel 11+ scheduling). Umbral y cadencia a
  fijar en el plan (sugerencia inicial: alertar si >0 filas con antigüedad >5 min).
- Es simultáneamente el detector de fallos de procesamiento y la alarma de pérdida de
  eventos — el hueco con el que empezó todo.

## Manejo de errores

- **Insert falla por violación de único:** es el caso de duplicado esperado → capturar
  y responder `200 {duplicate:true}`. No es error.
- **Insert falla por otra razón (BD caída):** propagar → `5xx`. Aquí sí queremos que
  Green reintente, porque no persistimos nada.
- **Job de procesamiento falla:** reintentos de cola normales (`$tries`). Si agota,
  la fila queda con `processed_at IS NULL` y el watcher la reporta. No se pierde.
- **Instancia no encontrada en `stateInstanceChanged`:** ya no es `return` silencioso;
  el job puede reintentar (la fila persiste) hasta que `id_instance` exista, o dejar
  la fila sin procesar para inspección. Definir política de reintento en el plan.

## Testing

Los tests actuales de `WebhookGreenBearerTest` solo afirman el *veredicto de dedupe*,
nunca que algo se guardó — por eso el agujero pasó desapercibido. Nuevos tests deben
afirmar **persistencia**:

- Cada `typeWebhook` (incluidos los "otros") crea exactamente una fila `int_webhooks`.
- Entrega duplicada (mismo `event_id` derivado) **no** crea segunda fila y responde
  `duplicate:true` — incluso con **caché fría** (verifica que la autoridad es la BD).
- `stateInstanceChanged: authorized` termina con la instancia en `authorized` y la
  fila con `processed_at` no nulo, vía el job.
- Carrera: `authorized` con `id_instance` aún inexistente → fila persiste, sin error;
  tras existir la instancia, el reintento del job la deja `authorized`.
- Watcher: filas viejas sin procesar son reportadas; filas procesadas no.
- Se conservan los casos valiosos de `resolveEventId` del suite actual (idMessage
  compartido, colisión entre instancias, transiciones en el mismo segundo, idMessage
  no escalar, fallback a body-hash).

## Limpieza asociada: eliminar `PUT /credentials/green-api`

Stub `501` nunca implementado; los tenants no necesitan ver credenciales Green (son
partner-provisioned). Se construyó como placeholder de bootstrap y ya no aporta.

- Quitar la ruta en `routes/api.php` y `CredentialsController`.
- Retirar el permiso `credenciales.gestionar` de `config/permissions.php`.
- Retirar `CredentialsStubTest`.
- Actualizar `docs/integration/waapi-api-contract.md` para dejar de anunciarlo a waapi.

Es una limpieza independiente pero pequeña; se agrupa aquí para no perderla. Puede ir
en su propio commit dentro del mismo plan.

## No confundir alcance

Esto **no** implementa recibos de entrega, mensajes entrantes como dominio, ni nuevos
tipos con lógica. Esos son features separados. Este spec solo garantiza que **ningún
evento de webhook se pierde jamás**, y que la deduplicación es durable.
