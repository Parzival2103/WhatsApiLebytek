<?php

namespace App\Services;

use App\Models\Core\Archivo;
use App\Support\Config\ConfigurationKey;
use Illuminate\Support\Facades\Storage;

class BrandingAssetService
{
    private const DEFAULT_PNG = 'iVBORw0KGgoAAAANSUhEhCQAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function resolveArchivo(?int $archivoId): ?Archivo
    {
        if ($archivoId === null) {
            return null;
        }

        $archivo = Archivo::withoutGlobalScopes()->find($archivoId);

        if ($archivo === null || ! Storage::disk($archivo->disk)->exists($archivo->path)) {
            return null;
        }

        return $archivo;
    }

    public function binaryForKey(ConfigurationKey $key, ConfigurationService $configuration, ?int $tenantId = null): string
    {
        $archivoId = $configuration->get($key, $tenantId);
        $archivo = $this->resolveArchivo(is_int($archivoId) ? $archivoId : null);

        if ($archivo !== null) {
            return Storage::disk($archivo->disk)->get($archivo->path);
        }

        return base64_decode(self::DEFAULT_PNG);
    }

    public function mimeForKey(ConfigurationKey $key, ConfigurationService $configuration, ?int $tenantId = null): string
    {
        $archivoId = $configuration->get($key, $tenantId);
        $archivo = $this->resolveArchivo(is_int($archivoId) ? $archivoId : null);

        return $archivo?->mime_type ?? 'image/png';
    }

    public function publicUrl(ConfigurationKey $key, ConfigurationService $configuration, ?int $tenantId = null): ?string
    {
        $routeName = match ($key) {
            ConfigurationKey::LogoArchivoId => 'branding.logo',
            ConfigurationKey::PwaIconArchivoId => 'branding.pwa_icon',
            ConfigurationKey::FaviconArchivoId => 'pwa.favicon',
            default => null,
        };

        if ($routeName === null) {
            return null;
        }

        if ($tenantId === null) {
            return $routeName === 'pwa.favicon' ? route('pwa.favicon') : null;
        }

        $archivoId = $configuration->get($key, $tenantId);
        $archivo = $this->resolveArchivo(is_int($archivoId) ? $archivoId : null);

        if ($archivo === null) {
            return $routeName === 'pwa.favicon' ? route('pwa.favicon') : null;
        }

        return route($routeName, ['hash' => substr($archivo->hash, 0, 12)]);
    }
}
