# Spec — Envío de mensajes a grupos WhatsApp (`recipient`)

**Fecha:** 2026-07-13  
**Repos:** `WhatsApiLebytek` (API), `docsV2` (docs públicas + sandbox)  
**Estado:** Aprobado en brainstorm — pendiente revisión del archivo  
**Enfoque elegido:** Normalizador dedicado + mismo `POST /messages` (campo `recipient` ampliado)

---

## 1. Problema

`POST /api/v1/messages` solo está pensado para contactos 1:1 (E.164 sin `+`). En producción ya se necesita enviar texto a **grupos** de WhatsApp.

Hoy:

1. `MessageSendService` hace `preg_replace('/\D+/', …)` y destruye cualquier sufijo `…@g.us`.
2. El cliente interno de envío ya acepta un destinatario con `@` y lo usa como chatId; si no hay `@`, añade el sufijo de chat individual.
3. Validación: `recipient` `max:32` y docs/sandbox asumen solo dígitos.

Resultado: un chatId de grupo no llega intacto al proveedor; el path de teléfono en producción no se puede romper.

---

## 2. Decisiones

| Decisión | Valor |
|----------|--------|
| Forma de API | Mismo campo `recipient` (opción A) |
| Formato de grupo | ChatId completo `digits@g.us` (no ID numérico suelto) |
| Listar/resolver grupos | Fuera de alcance (el cliente ya tiene el ID) |
| Campos nuevos (`chatId`, `recipientType`) | No en v1 |
| Endpoint nuevo | No |
| Campañas masivas | Fuera de alcance |
| Docs al cliente final | Sin mencionar el nombre del proveedor de WhatsApp |

---

## 3. Contrato público (cliente)

Endpoint sin cambio: **`POST /messages`**.

### `recipient` acepta

| Tipo | Ejemplo | Persistido / echo |
|------|---------|-------------------|
| Teléfono E.164 sin `+` | `5215512345678` | Solo dígitos (comportamiento actual) |
| ID de grupo WhatsApp | `120363012345678901@g.us` | String completo, sin reescribir |

### Sin cambios

- Headers, permisos (`mensajes.enviar`), idempotencia, `body`, `instancePublicId`
- Respuestas `202` / `200` idempotente, `409`, `429`
- Forma de `MessageResource` (sigue exponiendo `recipient`; sin `recipientType`)

### Lenguaje en docs públicas

Usar: “número E.164”, “ID de grupo WhatsApp (`…@g.us`)”, “Lebytek API”.  
**No** nombrar el proveedor técnico en `data.ts`, sandbox, `tester.php` ni textos orientados al cliente en el contrato público.

---

## 4. Diseño técnico (API)

### 4.1 `RecipientNormalizer`

Ubicación orientativa: `app/Services/Messaging/RecipientNormalizer.php`.

```
normalize(string $raw): string
```

| Entrada | Regla | Salida |
|---------|--------|--------|
| Contiene no-dígitos típicos de teléfono (`+`, espacios) o solo dígitos | Extraer dígitos; longitud 10–15 | Dígitos |
| Coincide `^\d{10,32}@g\.us$` (case-sensitive `g.us`) | Validar y devolver trim | Mismo chatId |
| Cualquier otro (`…@c.us` suelto, texto, `@g.us` incompleto) | Lanzar / fallar validación | — |

`MessageSendService::queueOutbound` deja de normalizar inline y usa este componente.

### 4.2 Validación HTTP

`StoreMessageRequest`:

- `recipient`: `required`, `string`, `max:48` (cubre dígitos + `@g.us`)
- Regla custom / regex que acepte **uno** de los dos formatos (misma semántica que el normalizer)

Errores → `422` con mensaje genérico (sin detalles del proveedor).

### 4.3 Cliente de envío

`InstanceClient::sendMessage` se mantiene:

- Si `recipient` ya tiene `@` → usarlo como chatId
- Si no → sufijo de chat individual

No hace falta método nuevo ni endpoint de proveedor distinto: el envío de texto es el mismo.

### 4.4 Persistencia

- Columna `int_mensajes.recipient` (`string`) sin migración de esquema
- Quotas / idempotencia / jobs: sin cambio de semántica
- Valor guardado = salida del normalizer (teléfono limpio o `…@g.us`)

---

## 5. Docs (`docsV2` + contrato API)

| Artefacto | Cambio |
|-----------|--------|
| `docs/integration/waapi-api-contract.md` | Documentar ambos formatos de `recipient` (lenguaje cliente) |
| `docsV2/src/data.ts` (sección Mensajes) | Ampliar “Formato de destinatario”: contacto + grupo; ejemplo JSON grupo; máx. longitud actualizada |
| `docsV2/src/lib/lebytekApi.ts` | `validateRecipient` acepta E.164 **o** `…@g.us` |
| `DemoSandbox.tsx` | UX/hint: permitir pegar ID de grupo; no forzar solo dígitos en el botón |
| `public/tester.php` | Ejemplo opcional comentado con grupo |

Sincronizar mirror del contrato en Framework solo si el equipo mantiene copia allí; la fuente de verdad de envío sigue siendo WhatsApiLebytek.

---

## 6. Tests

### Unit — `RecipientNormalizer`

- `5215512345678` / `+52 1 55…` → dígitos
- `120363012345678901@g.us` → intacto
- Rechazos: vacío, `foo`, `123@g.us` corto si aplica, `id@c.us`, `id@G.US`

### Feature — `MessageSendTest` (+ job / client fake)

- Teléfono: sin regresión (mismo `recipient` en JSON, job con destino individual)
- Grupo: `recipient` en DB/respuesta = `…@g.us`; fake HTTP recibe ese chatId
- `422` para formato inválido
- Idempotencia intacta

---

## 7. Criterios de aceptación

1. Cliente que hoy envía solo E.164 no cambia payload ni ve respuesta distinta.
2. `POST /messages` con `recipient` `…@g.us` válido encola y el job usa ese chatId.
3. Formatos inválidos → `422`.
4. Docs públicas y sandbox muestran la forma correcta **sin** nombrar al proveedor.
5. Tests unit + feature pasan en CI.

---

## 8. Fuera de alcance

- Endpoints para listar/crear/salir de grupos
- Inferir `@g.us` desde solo dígitos
- Media / plantillas / reacciones a grupos
- Campañas (`CampaignBatchJob`)
- Cambios de negocio en Lebytek_Framework (más allá de mirror de contrato si aplica)
- Deploy VPS (requiere orden explícita aparte)

---

## 9. Orden de implementación sugerido

1. `RecipientNormalizer` + tests unit
2. `StoreMessageRequest` + `MessageSendService` + tests feature
3. Contrato `waapi-api-contract.md`
4. `docsV2` (data, `lebytekApi`, sandbox, tester opcional)
5. Verificación local (`composer test` / tests del sandbox si existen)
