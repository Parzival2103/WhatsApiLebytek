# Spec — Delay de envío Green API (`delaySendMessagesMilliseconds`)

**Fecha:** 2026-07-23  
**Repos:** `WhatsApiLebytek` (api.lebytek.com)  
**Estado:** Plan listo — pendiente implementación  
**Plan:** [`docs/superpowers/plans/2026-07-23-green-api-delay-send-messages.md`](../plans/2026-07-23-green-api-delay-send-messages.md)

**Contexto:** Una cuenta de instancias recibió Yellow Card de Green API. El proveedor recomienda `delaySendMessagesMilliseconds: 15000` vía `setSettings` por instancia.

---

## 1. Problema

Green aplica el delay **por instancia**. Hoy `ProvisionGreenInstanceJob` configura webhook/flags en `setSettings`, pero **no** el delay. Las instancias nuevas y las ya existentes quedan sin esa mitigación anti-spam / Yellow Card.

### Lo que no se debe romper

- Payload actual de webhook en provisioning (`webhookUrl`, `webhookUrlToken`, `incomingWebhook`, `stateWebhook`).
- Credenciales, `publicId`, tokens Sanctum, ni el flujo QR/autorización.
- Throughput de colas Laravel (el delay lo aplica Green al enviar, no Horizon).
- Settings de webhook ya aplicadas en instancias existentes (el comando one-shot **solo** envía el delay; Green documenta actualización selectiva de parámetros).

---

## 2. Decisiones

| Decisión | Valor |
|----------|--------|
| Alcance | Instancias **nuevas** (provisioning) + **existentes** (comando one-shot) |
| Valor del delay | **Fijo** `15000` ms vía constante `GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS` (sin env/config en v1) |
| Enfoque | Constante compartida + ampliar `setSettings` del job + Artisan `green:apply-send-delay` |
| Payload del comando | Solo `delaySendMessagesMilliseconds` (no reescribe webhook) |
| Elegibles (comando) | `id_instance` usable; `api_token_instance` no nulo y **no vacío tras decrypt**; status **not in** `deleted`, `deleting` (incluye `failed` si hay credenciales) |
| Token cifrado | No filtrar `api_token_instance != ''` en SQL; validar con `filled()` en PHP |
| Fallos parciales | Continuar; capturar `\Throwable`; exit ≠ 0 si alguna falla |
| Dry-run | `--dry-run` lista elegibles; no llama HTTP |
| Efecto Green | Cada `setSettings` **reinicia** la instancia; settings aplican en hasta ~5 min |

---

## 3. Enfoques considerados

| # | Enfoque | Pros | Contras |
|---|---------|------|---------|
| A | Solo provisioning + curl/ops manual para existentes | Poco código | Frágil, no auditable |
| B | Jobs en cola por cada instancia existente | Rate-limit natural | Overkill con pocas instancias |
| **C (elegido)** | Provisioning + Artisan one-shot con `--dry-run` | Mínimo, reutiliza `InstanceClient`, operable en VPS | Un comando más a mantener |

Recomendación: **C**.

---

## 4. Diseño técnico

### 4.0 Constante compartida

```php
// app/Services/GreenApi/GreenApiInstanceSettings.php
final class GreenApiInstanceSettings
{
    public const DELAY_SEND_MESSAGES_MILLISECONDS = 15000;
}
```

Job y comando referencian esta constante (evitar literales duplicados).

### 4.1 Provisioning (instancias nuevas)

En `ProvisionGreenInstanceJob`, el `setSettings` existente pasa a:

```php
$client->setSettings([
    'webhookUrl' => config('services.green_api.webhook_url'),
    'webhookUrlToken' => config('services.green_api.webhook_secret'),
    'incomingWebhook' => 'yes',
    'stateWebhook' => 'yes',
    'delaySendMessagesMilliseconds' => GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS,
]);
```

### 4.2 Comando one-shot (instancias existentes)

- Clase: `App\Console\Commands\ApplyGreenSendDelayCommand`
- Signature: `green:apply-send-delay {--dry-run}`
- Query: `Instancia::query()->withoutGlobalScope('tenant')` con `whereNotIn('status', ['deleted', 'deleting'])`, `id_instance` no vacío, `api_token_instance` not null; luego filtrar con `filled()` tras decrypt
- Por instancia: `new InstanceClient(...)->setSettings(['delaySendMessagesMilliseconds' => GreenApiInstanceSettings::DELAY_SEND_MESSAGES_MILLISECONDS])`
- Output: conteo ok / fail; en fail imprimir `instancia_id` + mensaje (sin token)
- Exit code: `0` si todas OK (o dry-run); `1` si alguna falló

### 4.3 Ops post-deploy

1. Deploy api (`main`)
2. Preferir ventana de bajo tráfico (Green **reinicia** cada instancia al aplicar `setSettings`)
3. `php artisan green:apply-send-delay --dry-run`
4. `php artisan green:apply-send-delay`
5. Esperar hasta ~5 min a que Green aplique settings; no re-ejecutar en bucle cerrado
6. Nota en `docs/DEPLOY.md` (incluye warning de reboot)

### 4.4 Tests

| Caso | Esperado |
|------|----------|
| `ProvisionGreenInstanceJob` (Http::fake) | Request a `setSettings` incluye `delaySendMessagesMilliseconds = 15000` + webhooks |
| Comando `--dry-run` | No HTTP; lista/conteo de elegibles |
| Comando apply | Una o más instancias → POST setSettings con solo el delay |
| Comando con fallo parcial | Continúa; exit 1 |
| Elegibilidad | Incluye `failed` con credenciales; excluye `deleted` y `deleting` |

---

## 5. Fuera de alcance (v1)

- UI / panel waapi o back-office
- Variable de entorno o config para el valor del delay
- Ajustar rate limits / pacing en jobs de campañas Laravel
- Re-aplicar webhook settings en el comando (solo delay)
- Scheduler / cron automático del comando
- Verificación post-apply vía `getSettings` (ops manual opcional)

---

## 6. Criterios de aceptación

1. Toda instancia nueva sale de provisioning con delay 15000 en Green (vía constante compartida).
2. Tras correr el comando (sin dry-run), todas las instancias elegibles recibieron el setting o quedaron listadas como fallidas con exit ≠ 0.
3. Tests verdes para job + comando (incluye exclusión de `deleting`).
4. `docs/DEPLOY.md` menciona el one-shot post-deploy **y** el reboot / ventana de apply de Green.
