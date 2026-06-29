<?php

namespace App\Services;

use App\Models\Cfg\Configuracion;
use App\Models\Core\Tenant;
use App\Models\User;
use App\Support\Config\ConfigurationKey;
use App\Support\Config\ConfigurationRegistry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;

class ConfigurationService
{
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly AuditLogService $auditLog,
    ) {}

    public function get(ConfigurationKey $key, ?int $tenantId = null): mixed
    {
        $tenantId = $this->resolveTenantId($tenantId);
        $cacheKey = $this->cacheKey($tenantId, $key);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($key, $tenantId) {
            $record = Configuracion::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('key', $key->value)
                ->first();

            return $record?->value ?? ConfigurationRegistry::default($key);
        });
    }

    public function set(ConfigurationKey $key, mixed $value, User $actor): mixed
    {
        $validated = ConfigurationRegistry::validate($key, $value);
        $tenantId = $this->resolveTenantId($actor->tenant_id);

        $existing = Configuracion::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', $key->value)
            ->first();

        $before = $existing?->value;

        Configuracion::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'key' => $key->value,
            ],
            [
                'value' => $validated,
            ],
        );

        Cache::forget($this->cacheKey($tenantId, $key));

        $this->auditLog->record(
            action: 'config.updated',
            actor: $actor,
            subject: $existing,
            before: is_array($before) ? $before : ['value' => $before],
            after: is_array($validated) ? $validated : ['value' => $validated],
            request: request(),
        );

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPublicSnapshot(?int $tenantId = null): array
    {
        $tenantId = $this->resolveTenantIdOrNull($tenantId);

        if ($tenantId === null) {
            return $this->defaultsSnapshot();
        }

        return [
            'layoutMode' => $this->get(ConfigurationKey::LayoutMode, $tenantId),
            'themeColors' => $this->get(ConfigurationKey::ThemeColors, $tenantId),
            'appName' => $this->get(ConfigurationKey::AppName, $tenantId),
            'pwaThemeColor' => $this->get(ConfigurationKey::PwaThemeColor, $tenantId),
            'pwaBackgroundColor' => $this->get(ConfigurationKey::PwaBackgroundColor, $tenantId),
            'logoArchivoId' => $this->get(ConfigurationKey::LogoArchivoId, $tenantId),
            'faviconArchivoId' => $this->get(ConfigurationKey::FaviconArchivoId, $tenantId),
            'pwaIconArchivoId' => $this->get(ConfigurationKey::PwaIconArchivoId, $tenantId),
        ];
    }

    public function resolveTenantId(?int $tenantId = null): int
    {
        return $this->resolveTenantIdOrNull($tenantId)
            ?? throw new \RuntimeException('No tenant available for configuration resolution');
    }

    private function resolveTenantIdOrNull(?int $tenantId = null): ?int
    {
        if ($tenantId !== null) {
            return $tenantId;
        }

        $contextTenantId = TenantContext::id();

        if ($contextTenantId !== null) {
            return $contextTenantId;
        }

        return Tenant::query()->where('slug', 'default')->value('id')
            ?? Tenant::query()->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultsSnapshot(): array
    {
        return [
            'layoutMode' => ConfigurationRegistry::default(ConfigurationKey::LayoutMode),
            'themeColors' => ConfigurationRegistry::default(ConfigurationKey::ThemeColors),
            'appName' => ConfigurationRegistry::default(ConfigurationKey::AppName),
            'pwaThemeColor' => ConfigurationRegistry::default(ConfigurationKey::PwaThemeColor),
            'pwaBackgroundColor' => ConfigurationRegistry::default(ConfigurationKey::PwaBackgroundColor),
            'logoArchivoId' => ConfigurationRegistry::default(ConfigurationKey::LogoArchivoId),
            'faviconArchivoId' => ConfigurationRegistry::default(ConfigurationKey::FaviconArchivoId),
            'pwaIconArchivoId' => ConfigurationRegistry::default(ConfigurationKey::PwaIconArchivoId),
        ];
    }

    private function cacheKey(int $tenantId, ConfigurationKey $key): string
    {
        return "cfg:{$tenantId}:{$key->value}";
    }
}
