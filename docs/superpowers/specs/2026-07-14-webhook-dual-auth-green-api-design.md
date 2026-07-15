# Spec — Dual auth de webhooks entrantes (HMAC + Bearer Green API)

**Fecha:** 2026-07-14  
**Repos:** `WhatsApiLebytek` (api.lebytek.com)  
**Estado:** Implementado en worktree — pendiente commit/PR  
**Plan:** [`docs/superpowers/plans/2026-07-14-webhook-dual-auth-green-api.md`](../plans/2026-07-14-webhook-dual-auth-green-api.md)  

**Hallazgo origen:** AUTOMATION-01 A-002 (daily audit 2026-07-14)  
**Enfoque elegido:** Mantener HMAC como camino primario; **fallback Bearer** compatible con Green API, sin retirar ni debilitar el flujo actual

---

## 1. Problema

`POST /api/v1/webhooks/incoming` aplica dos middlewares:

| Middleware | Exige hoy | Realidad Green API |
|------------|-----------|-------------------|
| `VerifyWebhookSignature` (`webhook.signature`) | Header `X-Webhook-Signature` = HMAC-SHA256(body, `WEBHOOK_SECRET`) | **No** envía ese header |
| `WebhookIdempotency` (`webhook.idempotency`) | Header `X-Event-Id` no vacío | **No** documenta / envía ese header |

Al provisionar una instancia, `ProvisionGreenInstanceJob` configura:

- `webhookUrl` → `…/api/v1/webhooks/incoming`
- `webhookUrlToken` → `WEBHOOK_SECRET` (vía `config('services.green_api.webhook_secret')`)

Según la documentación de Green API, con `webhookUrlToken` no vacío el proveedor reenvía el evento con:

```http
Authorization: Bearer <token>
```

(o `Basic …` si se configura explícitamente ese prefijo; **fuera de alcance** de esta spec: solo Bearer).

Resultado en runtime: los webhooks reales de Green se rechazan en **401** (falta firma HMAC) o, si se relajara solo la firma, en **422** (falta `X-Event-Id`). El controller que actualiza `stateInstanceChanged` nunca se ejecuta con tráfico real.

Los tests actuales (`WebhookVerificationTest`) solo cubren el cliente HMAC sintético; no reproducen headers Green.

### Lo que no se debe romper

- Clientes / tests que **sí** usan HMAC + `X-Event-Id` deben seguir funcionando igual.
- El endpoint **no** puede quedar abierto (sin autenticar).
- No reutilizar tokens Sanctum / `LEBYTEK_API_TOKEN` como auth de webhook.

---

## 2. Decisiones

| Decisión | Valor |
|----------|--------|
| ¿Retirar HMAC? | **No** |
| Orden de auth | 1) HMAC si llega `X-Webhook-Signature`; 2) Bearer si no hay firma HMAC; 3) si no hay ninguno válido → 401 |
| Si `X-Webhook-Signature` está presente pero es inválida | **401 inmediato** — no hacer fallback a Bearer (evita ataques de “firma mala + Bearer robado parcial” y deja el camino HMAC estricto) |
| Si ambos headers presentes y HMAC válido | Aceptar por HMAC; no hace falta validar Bearer |
| Secret compartido | Mismo `WEBHOOK_SECRET` / `config('services.webhooks.secret')` (ya usado como `webhookUrlToken`) |
| Basic Auth Green | Fuera de alcance (v1 Bearer only) |
| Abrir endpoint sin secret configurado | **No** — secret vacío sigue siendo 500 |
| Idempotencia | Incluida en esta spec (sin ella Green sigue en 422) |
| Persistencia `int_webhooks` | **Fuera de alcance** (A-004 / fase posterior) |
| Ampliar tipos de webhook procesados (`incomingMessageReceived`, etc.) | **Fuera de alcance** — solo desbloquear auth + idempotencia |
| Nuevo endpoint separado `/webhooks/green` | **No** — mismo path, same group |
| Cambiar `webhookUrlToken` en provisioning | **No** en v1 (ya apunta al secret correcto) |

---

## 3. Enfoques considerados

| # | Enfoque | Pros | Contras |
|---|---------|------|---------|
| A | Solo Bearer; quitar HMAC del group | Simple para Green | Rompe tests y diseño del núcleo; pierde camino para otros emisores |
| B | Endpoint distinto solo para Green | Aislamiento | Duplica routing, docs, riesgo de configurar mal `webhookUrl` |
| **C (elegido)** | **Mismo endpoint; middleware de auth dual (HMAC primario + Bearer fallback)** | Un path; HMAC intacto; Green funciona | Lógica un poco más rica; hay que documentar ambos modos |

Recomendación: **C**.

---

## 4. Diseño técnico

### 4.1 Auth — `VerifyWebhookSignature` (ampliar, no reemplazar)

Misma clase / alias de middleware `webhook.signature`. Semántica nueva:

```
secret = config('services.webhooks.secret')
si secret vacío → 500 (sin cambio)

si header X-Webhook-Signature no vacío:
    expected = hash_hmac('sha256', rawBody, secret)
    si hash_equals(expected, signature) → next
    si no → 401 Invalid webhook signature
    (NO intentar Bearer)

si no hay X-Webhook-Signature:
    parsear Authorization
    si esquema Bearer y token no vacío:
        si hash_equals(secret, token) → next
        si no → 401 Invalid webhook bearer token
    si no → 401 Missing webhook authentication
      (mensaje que indique falta firma o Bearer; sin filtrar el secret)
```

Reglas de seguridad:

- Comparaciones con `hash_equals` (timing-safe).
- No loguear el secret ni el token completo.
- Body para HMAC: `getContent()` crudo (igual que hoy).
- Bearer: solo el token, no aceptar `Bearer` vacío ni “solo el secret en query/body”.

**Preferencia HMAC cuando ambos existen:** si llega `X-Webhook-Signature`, solo se valida HMAC. Un emisor Green típico **no** manda ese header, así que cae al fallback Bearer.

Opcional (no obligatorio en v1): atributo de request `webhook_auth_mode` = `hmac` | `bearer` para logs/tests.

### 4.2 Idempotencia — `WebhookIdempotency` (ampliar)

Hoy: sin `X-Event-Id` → 422. Eso bloquea Green aunque auth pase.

Nueva resolución de `eventId` (primera coincidencia válida):

1. Header `X-Event-Id` (string no vacío tras trim) — camino actual / otros emisores HMAC.
2. Campo del JSON de Green, en este orden:
   - `idMessage` (string/num coerció a string)
   - `idWebhook` si existe en payload
   - composición estable: `{typeWebhook}|{idInstance}|{timestamp}` cuando los tres existen y no son vacíos  
     (`idInstance` desde `instanceData.idInstance` o raíz `idInstance`; `timestamp` desde campo Green documentado / usado en payloads reales)
3. Si nada de lo anterior: `sha1(rawBody)` como último recurso.

Luego:

- Cache key: `webhook:event:` + `sha1(eventId)` (igual que hoy).
- Hit → respuesta 200 `{ received: true, duplicate: true }` **sin** re-ejecutar el controller (comportamiento actual vía early return o atributo `webhook_duplicate`).
- Miss → continuar; tras respuesta exitosa, `Cache::put` 24h.

**Importante:** con auth Bearer y dedupe por hash de body, reintentos bit-idénticos de Green se colapsan; reintentos con body distinto y sin ids estabilizados podrían duplicar procesamiento. Aceptable en v1 hasta `int_webhooks` durable.

No aceptar requests **autenticados sin** event key derivable: el fallback `sha1(body)` siempre produce una key, así que no hay 422 por “missing event id” en el path Green.

### 4.3 Rutas

Sin cambio de path:

```
POST /api/v1/webhooks/incoming
middleware: webhook.signature, webhook.idempotency
```

Orden de middlewares se mantiene (auth antes que idempotencia).

### 4.4 Controller

Sin cambio funcional obligatorio en v1:

- Sigue procesando `stateInstanceChanged`.
- No persiste en `int_webhooks` (fuera de alcance).

### 4.5 Config / env

- Fuente de verdad del secret: `WEBHOOK_SECRET` → `services.webhooks.secret` y `services.green_api.webhook_secret` (ya aliasados).
- Doc / `.env.example`: aclarar que el mismo valor sirve para HMAC **y** para `webhookUrlToken` (Bearer que envía Green).
- Drift `GREEN_API_WEBHOOK_TOKEN` vs `WEBHOOK_SECRET` (A-006): documentar o unificar en el mismo PR de implementación / doc follow-up; no inventar un segundo secret “Green-only” en v1.

### 4.6 Provisioning

Sin cambios de código requeridos: ya envía `webhookUrlToken` = secret. Tras el fix de middleware, Green usará Bearer contra ese valor.

---

## 5. Contrato / documentación

Actualizar (misma entrega o PR docs acoplado):

| Artefacto | Cambio |
|-----------|--------|
| `docs/integration/waapi-api-contract.md` | Incoming webhook: auth aceptada = HMAC `X-Webhook-Signature` **o** `Authorization: Bearer {WEBHOOK_SECRET}`; idempotencia = `X-Event-Id` **o** id derivado del payload |
| Comentarios Scribe en `IncomingWebhookController` | Reflejar dual auth |
| `docs/DEPLOY.md` / `.env.example` | Que `WEBHOOK_SECRET` es el token que Green reenvía como Bearer |

Lenguaje: no hace falta nombrar Green en docs públicas orientadas a cliente final si el contrato interno ya lo distingue; el contrato de integración api↔ops **sí** debe mencionar ambos modos.

---

## 6. Tests

Ampliar `tests/Feature/Webhooks/WebhookVerificationTest` (y/o nuevo archivo Feature):

| Caso | Expectativa |
|------|-------------|
| HMAC válido + `X-Event-Id` (existente) | 200, no duplicate |
| HMAC inválido (existente) | 401 — **sin** aceptar Bearer aunque también venga |
| HMAC ausente + Bearer correcto + payload `stateInstanceChanged` | 200; instancia actualiza `green_state` / `status` |
| Bearer incorrecto | 401 |
| Sin `X-Webhook-Signature` ni Bearer | 401 |
| Secret no configurado | 500 |
| Bearer OK, sin `X-Event-Id`, body Green con `idMessage` o solo body | 200 (no 422) |
| Segundo POST idéntico (mismo event key) | 200 `duplicate: true`, sin doble update destructivo |

Fixtures: payload mínimo realista de Green `stateInstanceChanged` (campos que ya consume el controller).

---

## 7. Criterios de aceptación

1. Un POST con headers/estilo Green API (`Authorization: Bearer {WEBHOOK_SECRET}`, sin `X-Webhook-Signature` / sin `X-Event-Id`) llega al controller y puede marcar `authorized` en `int_instancias` cuando el payload es `stateInstanceChanged`.
2. El flujo HMAC + `X-Event-Id` existente sigue verde en CI.
3. Requests sin HMAC válido ni Bearer válido siguen en 401/500 según caso; no hay modo “trust all”.
4. Firma HMAC presente pero incorrecta no se salva con un Bearer en la misma request.
5. Docs de contrato/deploy reflejan dual auth.
6. Sin migración nueva; sin cambio de `ProvisionGreenInstanceJob` salvo bug descubierto al smoke.

---

## 8. Fuera de alcance (explícito)

- Persistencia durable en `int_webhooks` (A-004).
- Procesar `incomingMessageReceived` y otros `typeWebhook`.
- Basic Auth de Green.
- Rate limiting específico del endpoint (más allá de lo global actual).
- Cambiar CI PHP / PR #13.
- Smoke manual en VPS (se recomienda post-merge, no bloquea el diseño).

---

## 9. Riesgos y mitigaciones

| Riesgo | Mitigación |
|--------|------------|
| Secret compartido filtrado = webhooks forjables | Secret fuerte; solo HTTPS; rotación documentada en deploy |
| Dedupe por `sha1(body)` demasiado fino/grueso | Preferir `idMessage` / composición; luego hash; migrar a `int_webhooks` después |
| Confundir Bearer de webhook con Sanctum | Guard distinto; middleware dedicado; docs claras |
| Implementer quita HMAC “para simplificar” | Esta spec prohíbe retirar el camino HMAC |

---

## 10. Plan de implementación (alto nivel)

1. Extender `VerifyWebhookSignature` con fallback Bearer (reglas §4.1).
2. Extender `WebhookIdempotency` con resolución de event id (§4.2).
3. Tests Feature (§6).
4. Actualizar contrato + `.env.example` / DEPLOY (§5).
5. (Post-merge, humano) Smoke con instancia Green real en VPS.

Detalle de commits/tareas → plan en `docs/superpowers/plans/` tras aprobación de este spec.

---

## 11. Resumen

Mantener el middleware HMAC actual como camino primario estricto; añadir **fallback Bearer** alineado con `webhookUrlToken` de Green; relajar solo la **fuente** del id de idempotencia (no la existencia de dedupe). Mismo endpoint, sin abrir el canal, sin retirar seguridad HMAC para emisores que ya la usan.
