<?php

namespace App\Http\Controllers;

use App\Services\ConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ManifestController extends Controller
{
    public function __invoke(ConfigurationService $configuration): JsonResponse
    {
        $tenantId = $configuration->resolveTenantIdOrNull();
        $cacheKey = 'pwa.manifest:'.($tenantId ?? 'default');

        $manifest = Cache::remember($cacheKey, 3600, function () use ($configuration, $tenantId) {
            $snapshot = $configuration->getPublicSnapshot($tenantId);
            $version = hash('sha256', json_encode($snapshot));

            return [
                'name' => $snapshot['appName'],
                'short_name' => $snapshot['appName'],
                'start_url' => '/',
                'display' => 'standalone',
                'background_color' => $snapshot['pwaBackgroundColor'],
                'theme_color' => $snapshot['pwaThemeColor'],
                'icons' => $this->icons($snapshot),
                'version' => $version,
            ];
        });

        return response()
            ->json($manifest)
            ->header('Cache-Control', 'public, max-age=3600')
            ->header('ETag', '"'.$manifest['version'].'"');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function icons(array $snapshot): array
    {
        return [[
            'src' => url('/favicon.ico'),
            'sizes' => '512x512',
            'type' => 'image/png',
        ]];
    }
}
