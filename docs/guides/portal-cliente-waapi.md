# Portal cliente waapi.lebytek.com

Panel de **solo lectura** para clientes con demo o producción activa. Consume **únicamente** `api.lebytek.com`; no llama Green API directo.

## Acceso

1. Recibe el correo de credenciales con token Sanctum por-tenant.
2. Abre [https://waapi.lebytek.com/portal/acceso](https://waapi.lebytek.com/portal/acceso).
3. Pega el token (se guarda en sesión server-side; no uses localStorage).
4. Dashboard → estado de instancia → QR si `waiting_qr`.

## Rutas

| Ruta | Descripción |
|------|-------------|
| `/` | Landing producto WhatsApp API |
| `/portal/acceso` | Login con token |
| `/portal/dashboard` | Estado instancia |
| `/portal/qr` | Código QR (proxy `GET /instances/{id}/qr`) |
| `/portal/uso` | Contadores (placeholder hasta endpoint de uso) |

## Deploy (Framework)

En el vhost `waapi.lebytek.com`:

```env
WAAPI_PORTAL_ENABLED=true
LEBYTEK_API_URL=https://api.lebytek.com/api/v1
MKT_EMAIL_DOCS_URL=https://docs.lebytek.com
MKT_EMAIL_DASHBOARD_URL=https://waapi.lebytek.com/portal/acceso
```

No se requiere `LEBYTEK_API_TOKEN` de plataforma en waapi (los clientes usan token por-tenant).

## Contrato relacionado

Ver [waapi-api-contract.md](integration/waapi-api-contract.md) — autenticación token por-tenant e instancias.
