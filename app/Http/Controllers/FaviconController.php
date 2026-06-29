<?php

namespace App\Http\Controllers;

use App\Models\Core\Archivo;
use App\Services\ConfigurationService;
use App\Support\Config\ConfigurationKey;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class FaviconController extends Controller
{
    private const DEFAULT_PNG = 'iVBORw0KGgoAAAANSUhEhCQAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function __invoke(ConfigurationService $configuration): Response
    {
        $tenantId = $configuration->resolveTenantIdOrNull();
        $cacheKey = 'pwa.favicon:'.($tenantId ?? 'default');

        $binary = Cache::remember($cacheKey, 3600, function () use ($configuration, $tenantId) {
            $archivoId = $configuration->get(ConfigurationKey::FaviconArchivoId, $tenantId);

            if ($archivoId) {
                $archivo = Archivo::withoutGlobalScopes()->find($archivoId);

                if ($archivo && Storage::disk($archivo->disk)->exists($archivo->path)) {
                    return Storage::disk($archivo->disk)->get($archivo->path);
                }
            }

            return base64_decode(self::DEFAULT_PNG);
        });

        return response($binary, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
