<?php

namespace App\Http\Controllers;

use App\Services\BrandingAssetService;
use App\Services\ConfigurationService;
use App\Support\Config\ConfigurationKey;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class BrandingAssetController extends Controller
{
    public function logo(BrandingAssetService $assets, ConfigurationService $configuration): Response
    {
        return $this->serve(ConfigurationKey::LogoArchivoId, $assets, $configuration, 'branding.logo');
    }

    public function pwaIcon(BrandingAssetService $assets, ConfigurationService $configuration): Response
    {
        return $this->serve(ConfigurationKey::PwaIconArchivoId, $assets, $configuration, 'branding.pwa_icon');
    }

    private function serve(
        ConfigurationKey $key,
        BrandingAssetService $assets,
        ConfigurationService $configuration,
        string $cachePrefix,
    ): Response {
        $tenantId = $configuration->resolveTenantIdOrNull();
        $cacheKey = "{$cachePrefix}:".($tenantId ?? 'default');

        $payload = Cache::remember($cacheKey, 3600, function () use ($assets, $configuration, $tenantId, $key) {
            $binary = $assets->binaryForKey($key, $configuration, $tenantId);
            $mime = $assets->mimeForKey($key, $configuration, $tenantId);
            $etag = hash('sha256', $binary);

            return compact('binary', 'mime', 'etag');
        });

        return response($payload['binary'], 200, [
            'Content-Type' => $payload['mime'],
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => '"'.$payload['etag'].'"',
        ]);
    }
}
