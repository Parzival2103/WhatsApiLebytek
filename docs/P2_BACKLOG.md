# Backlog P2 — post-deploy / vertical WhatsApp

Items planificados después del deploy del núcleo. Infraestructura base ya incluida donde aplica.

| Item | Estado | Ubicación |
|------|--------|-----------|
| Idempotency-Key API writes | Middleware base | `App\Http\Middleware\ApiIdempotencyKey` (activo en rutas POST/PATCH `/api/v1/*`) |
| Cuota/uso por tenant | Hook Redis | `App\Services\TenantUsageService` |
| Cascada delete tenant | Comando soft-delete | `php artisan tenant:purge {slug}` |
| Sentry/Flare | Env placeholders | `.env.example` — instalar paquetes en sprint observabilidad |
| Alertas Horizon | Config env | `HORIZON_MAIL`, `HORIZON_SLACK_*` en `config/horizon.php` |
| 2FA Fortify | Pendiente | Feature flag `AUTH_2FA_ENABLED` cuando se integre Fortify |
| OpenAPI completo | Parcial | Scribe documenta health + webhooks; ampliar con vertical WhatsApp |
| ULID en modelos `dom_*` | Convención | Trait `HasUlidRouteKey` al agregar verticales |

## Uso del hook de cuota (ejemplo vertical)

```php
app(TenantUsageService::class)->increment($tenant, 'messages.sent');
```

## Purga de tenant

```bash
php artisan tenant:purge default --force
```

Soft-delete del tenant; jobs de anonimización por vertical se registran en el comando.
