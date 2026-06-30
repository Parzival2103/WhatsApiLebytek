<?php

namespace App\Http\Controllers;

use App\Services\BrandingAssetService;
use App\Services\ConfigurationService;
use App\Support\Config\ConfigurationKey;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class FaviconController extends Controller
{
    public function __invoke(BrandingAssetService $assets, ConfigurationService $configuration): Response
    {
        $tenantId = $configuration->resolveTenantIdOrNull();
        $cacheKey = 'pwa.favicon:'.($tenantId ?? 'default');

        $payload = Cache::remember($cacheKey, 3600, function () use ($assets, $configuration, $tenantId) {
            $binary = $assets->binaryForKey(ConfigurationKey::FaviconArchivoId, $configuration, $tenantId);
            $mime = $assets->mimeForKey(ConfigurationKey::FaviconArchivoId, $configuration, $tenantId);

            return [
                'binary' => $binary,
                'mime' => $mime,
                'etag' => hash('sha256', $binary),
            ];
        });

        return response($payload['binary'], 200, [
            'Content-Type' => $payload['mime'],
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => '"'.$payload['etag'].'"',
        ]);
    }
}
