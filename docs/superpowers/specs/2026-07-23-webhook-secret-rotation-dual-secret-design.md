# Spec — Rotación de `WEBHOOK_SECRET` sin downtime (dual secret + verify)

**Fecha:** 2026-07-23  
**Repos:** `WhatsApiLebytek` (api.lebytek.com)  
**Estado:** Plan listo — pendiente implementación  
**Plan:** [`docs/superpowers/plans/2026-07-23-webhook-secret-rotation-dual-secret.md`](../plans/2026-07-23-webhook-secret-rotation-dual-secret.md)

**Contexto:** Operación manual primero; comando listo para cron posterior (modo verify por defecto).  
**No afecta** `publicId` de tenants/instancias ni tokens Sanctum de clientes.

---

## 1. Problema

Hoy la API acepta **un solo** `WEBHOOK_SECRET` (HMAC `X-Webhook-Signature` o Bearer Green `webhookUrlToken`). Rotar el secret implica:

1. Actualizar `.env` + `config:cache`
2. `setSettings(webhookUrlToken=…)` en **todas** las instancias Green

Entre (1) y (2) hay ventana de `401` → pérdida de eventos. Automatizar eso a ciegas en un cron nocturno es frágil.

Además, tras rotar hace falta una **prueba determinista** (no depender de un webhook “real” de Green) y alertas a ops (WhatsApp si OK / correo si auth falla).

### Lo que no se debe romper

- Clientes finales: mismos `publicId`, mismos tokens, mismo `POST /messages`.
- Camino HMAC y Bearer actuales (dual-auth 2026-07-14).
- Endpoint no abierto sin secret configurado (secret actual vacío → 500).
- No exponer el secret en logs, WhatsApp ni correo.

---

## 2. Decisiones

| Decisión | Valor |
|----------|--------|
| Dual secret | `WEBHOOK_SECRET` (actual) **o** `WEBHOOK_SECRET_PREVIOUS` (gracia) |
| Orden de match | Probar actual; si no, previous; si ninguno → 401 |
| HMAC con previous | Sí: `hash_hmac` con actual **o** previous |
| Bearer con previous | Sí: `hash_equals` contra actual **o** previous |
| Firma HMAC presente pero inválida contra ambos | 401 — **sin** caer a Bearer en el mismo request (misma regla que dual-auth) |
| `setSettings` / provisioning | Solo escribe el **actual** |
| Verificación post-rotación | Probe interno `POST /api/v1/webhooks/incoming` (opción A) |
| WhatsApp a ops | Notificación de **éxito** del probe (no valida el secret por sí solo) |
| Fallo probe (401 u otro ≠ 2xx) | Correo a `HORIZON_MAIL` |
| Comando | Híbrido: default = verify; flags mutadores explícitos |
| Cron v1 | Solo documentar; ejecutar **verify** (sin `--write-env` / `--apply-green`) |
| Escritura `.env` | Solo con `--write-env` (manual / futuro cron supervisado); escritura **atómica** (temp + rename) |
| Config cache | Si `configurationIsCached()`, `--write-env` / `--clear-previous` **exigen** `--cache-config` (abort antes de mutar). El probe HTTP pega a PHP-FPM; sin cache refresh no hay gracia dual en ingress |
| Flags conflictivos | `--write-env` + `--clear-previous` en la misma invocación → rechazo (destruye ventana de gracia) |
| `apply-green` eligibility | Igual espíritu que apply-delay: no `deleted`; `id_instance` + token no vacíos |
| Números WhatsApp | Nuevas vars en api (no existen hoy en `.env.example` de la API; Framework tiene `MKT_ALERT_*` en otro repo) |
| Correo | Reutilizar `HORIZON_MAIL`; mailable de fallo **síncrono** (no `ShouldQueue`) |
| OPS WA path | Vía `MessageSendService` (incluye `refreshFromGreen` + guard módulo + cuota); instancia ops debe estar `authorized` |

---

## 3. Enfoques considerados

| # | Enfoque | Pros | Contras |
|---|---------|------|---------|
| A | Solo verify + ops edita `.env` / Green a mano | Seguro | Fácil olvidar un paso |
| B | Rotación full automática siempre | Cómodo | Peligroso sin dual + sin alerta |
| **C (elegido)** | Dual secret + comando híbrido (verify default; flags para mutate) | Sin downtime; apto cron gradual | Más código / tests |

Recomendación: **C**.

---

## 4. Diseño técnico

### 4.1 Config

`config/services.php`:

```php
'webhooks' => [
    'secret' => env('WEBHOOK_SECRET'),
    'previous_secret' => env('WEBHOOK_SECRET_PREVIOUS'), // opcional, vacío = deshabilitado
],
```

`green_api.webhook_secret` sigue apuntando solo a `WEBHOOK_SECRET` (actual) para provisioning / `setSettings`.

`.env.example` (api):

```env
WEBHOOK_SECRET=
# Gracia post-rotación: Green puede seguir un tiempo con el token viejo
WEBHOOK_SECRET_PREVIOUS=

HORIZON_MAIL=

# Ops alerts (rotación / verify) — CSV de E.164 sin +
OPS_ALERT_WHATSAPP_NUMBERS=
# Instancia authorized de plataforma/demo ops para enviar esas alertas
OPS_ALERT_INSTANCE_PUBLIC_ID=
```

### 4.2 Middleware `VerifyWebhookSignature`

1. Exigir `webhooks.secret` no vacío (igual que hoy) → si falta, 500.
2. Construir lista de secrets válidos: `[actual]` + `[previous]` si `previous` es string no vacío y distinto de actual.
3. Si hay `X-Webhook-Signature`:
   - Aceptar si algún secret produce HMAC válido (`hash_equals`).
   - Si ninguno → 401 (no fallback Bearer).
4. Si no hay firma: Bearer debe matchear algún secret de la lista.
5. Logs: modo `hmac`/`bearer` + flag booleano `used_previous_secret` (nunca el valor del secret).

### 4.3 Comando Artisan `webhooks:rotate-secret`

**Namespace sugerido:** `App\Console\Commands\RotateWebhookSecretCommand`

| Flag | Efecto |
|------|--------|
| (ninguno) | **Verify only:** probe actual (y previous si configurado); OK → WhatsApp; fail → mail |
| `--write-env` | Genera `bin2hex(random_bytes(32))`; actual → previous; nuevo → actual; escribe `.env` (keys existentes o append, atómico). Si config está cacheado y falta `--cache-config` → **FAILURE sin mutar** |
| `--cache-config` | Tras `--write-env` / `--clear-previous`, `Artisan::call('config:cache')`. Obligatorio cuando `configurationIsCached()` |
| `--apply-green` | Para cada `Instancia` elegible (no `deleted`, credenciales): `InstanceClient::setSettings` con `webhookUrl` + `webhookUrlToken` = secret **actual** + flags webhook de provisioning. Si provisioning ya envía `delaySendMessagesMilliseconds`, incluirlo también |
| `--clear-previous` | Vacía `WEBHOOK_SECRET_PREVIOUS` en `.env` (+ `--cache-config` si config cacheado) |
| `--dry-run` | Loguea acciones sin mutar `.env` ni Green ni probe ni WA/mail |

**Orden manual seguro (runbook):**

```text
0. Deploy del código dual-secret + verify-only smoke (sin mutar)
1. php artisan webhooks:rotate-secret --write-env --cache-config
2. php artisan webhooks:rotate-secret --apply-green
3. php artisan webhooks:rotate-secret          # verify
4. (días después) php artisan webhooks:rotate-secret --clear-previous --cache-config
```

**Cron futuro (documentar, no registrar en `routes/console.php` en v1):**

```cron
# Solo verify — no rotar a ciegas
0 5 * * 0 cd /path && php artisan webhooks:rotate-secret
```

Rotación automática con `--write-env --apply-green` queda **fuera de v1** hasta que dual-secret + alertas estén estables en prod.

### 4.4 Probe HTTP (verify)

- Cliente HTTP interno a `config('services.ops.probe_base_url') ?: config('app.url')` + `/api/v1/webhooks/incoming`. En prod, `APP_URL` debe ser reachable desde el VPS.
- Headers: `Authorization: Bearer {current_secret}`, `X-Event-Id: rotate-probe-{ulid}`, `Accept: application/json`, `Content-Type: application/json`.
- Body mínimo sintético, p. ej.:

```json
{
  "typeWebhook": "incomingMessageReceived",
  "idInstance": "0",
  "timestamp": 0,
  "messageData": { "typeMessage": "textMessage", "textMessageData": { "textMessage": "webhook-secret-rotate-probe" } }
}
```

- Éxito: HTTP 2xx (`received` true; puede ser duplicate si se reusa id — usar ULID único).
- También probe opcional con Bearer = previous si está configurado (ambos deben 2xx durante gracia).
- **No** loguear Authorization ni body completo en failure messages a WA/mail (solo status + reason corto).

**Skip-persist (incluido en el plan):** `IncomingWebhookController` detecta `X-Event-Id` con prefijo `rotate-probe-` y responde `{received:true,probe:true}` **sin** persistir ni dispatch job (auth middleware sigue aplicando).

### 4.5 Notificaciones

| Resultado | Acción |
|-----------|--------|
| Probe OK | WhatsApp a cada número en `OPS_ALERT_WHATSAPP_NUMBERS` vía instancia `OPS_ALERT_INSTANCE_PUBLIC_ID` (MessageSendService / flujo mensajes existente). Texto fijo: rotación/verify OK + timestamp + hostname. Si vars vacías → log warning, exit 0 si probe OK |
| Probe fail | Mail a `HORIZON_MAIL` (Laravel `Mail::raw` o Mailable simple). Asunto: `[api] webhook secret verify failed`. Si `HORIZON_MAIL` vacío → log error + exit code ≠ 0 |
| WA send fail tras probe OK | Log + mail opcional “verify OK pero WA falló” (no marcar verify como fallido) |

No incluir secret ni tokens en el cuerpo.

### 4.6 Impacto en clientes

**Ninguno** en IDs públicos ni auth de API de negocio. Solo cambia el token que Green envía a la API (lado proveedor).

---

## 5. Tests

| Caso | Esperado |
|------|----------|
| Bearer actual | 200 |
| Bearer previous (previous set) | 200 |
| Bearer basura | 401 |
| HMAC actual | 200 |
| HMAC previous | 200 |
| HMAC inválido con header presente | 401 (no Bearer fallback) |
| Previous vacío | solo actual válido |
| Comando verify 200 | Notification/Mail fakes: WA disparado (si env test set), no mail fail |
| Comando verify 401 | Mail enviado a HORIZON_MAIL |
| `--dry-run` | no escribe `.env`, no setSettings |

---

## 6. Fuera de alcance (v1)

- Registrar cron en scheduler Laravel.
- Secret distinto por instancia Green.
- Rotación automática unattended con `--write-env` en producción.
- Cambiar o rotar tokens Sanctum / `publicId`.
- TTL / purge de filas probe en `int_webhooks` (salvo skip-persist opcional arriba).
- Copiar automáticamente números desde Framework `MKT_ALERT_WHATSAPP_NUMBERS`.

---

## 7. Runbook ops (resumen)

1. Confirmar deploy del middleware dual-secret **antes** del primer `--write-env`.
2. Confirmar `HORIZON_MAIL`, `OPS_ALERT_*`, `APP_URL` reachable; smoke `webhooks:rotate-secret` (verify only).
3. Rotar con flags (sección 4.3) — siempre `--cache-config` en prod al mutar `.env`.
4. Verificar mensaje WA / mail / exit code.
5. Tras gracia (p. ej. 7 días) y cero 401 por secret viejo: `--clear-previous --cache-config`.
6. Ante desastre: restaurar secrets en `.env` desde gestor + `--cache-config` + `--apply-green`.

---

## 8. Criterios de aceptación

1. Con `WEBHOOK_SECRET_PREVIOUS` poblado, Green (o cliente) con token viejo **y** nuevo autentican.
2. `php artisan webhooks:rotate-secret` sin flags valida auth y notifica según resultado.
3. `--write-env` / `--apply-green` no corren salvo flag explícito.
4. Documentado en `DEPLOY.md` o runbook corto enlazado desde esta spec.
5. Tests Pest verdes para middleware + comando verify.
6. Ningún cambio de contrato `/messages` / `publicId` / tokens cliente.
