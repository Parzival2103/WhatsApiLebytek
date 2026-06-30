<?php

namespace App\Http\Controllers\Admin\Config;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Config\UpdateBrandingConfigRequest;
use App\Services\ConfigurationService;
use App\Services\SecureUploadService;
use App\Support\Config\ConfigurationKey;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BrandingConfigController extends Controller
{
    public function edit(ConfigurationService $configuration): Response
    {
        return Inertia::render('Admin/Config/Branding', [
            'appName' => $configuration->get(ConfigurationKey::AppName),
            'pwaThemeColor' => $configuration->get(ConfigurationKey::PwaThemeColor),
            'pwaBackgroundColor' => $configuration->get(ConfigurationKey::PwaBackgroundColor),
            'logoArchivoId' => $configuration->get(ConfigurationKey::LogoArchivoId),
            'faviconArchivoId' => $configuration->get(ConfigurationKey::FaviconArchivoId),
            'pwaIconArchivoId' => $configuration->get(ConfigurationKey::PwaIconArchivoId),
            'logoUrl' => $configuration->getPublicSnapshot()['logoUrl'] ?? null,
            'faviconUrl' => $configuration->getPublicSnapshot()['faviconUrl'] ?? null,
            'pwaIconUrl' => $configuration->getPublicSnapshot()['pwaIconUrl'] ?? null,
        ]);
    }

    public function update(
        UpdateBrandingConfigRequest $request,
        ConfigurationService $configuration,
        SecureUploadService $uploader,
    ): RedirectResponse {
        $validated = $request->validated();
        $user = $request->user();

        $configuration->set(ConfigurationKey::AppName, $validated['appName'], $user);
        $configuration->set(ConfigurationKey::PwaThemeColor, $validated['pwaThemeColor'], $user);
        $configuration->set(ConfigurationKey::PwaBackgroundColor, $validated['pwaBackgroundColor'], $user);

        if ($request->hasFile('logo')) {
            $archivo = $uploader->store($request->file('logo'), $user, 'logo');
            $configuration->set(ConfigurationKey::LogoArchivoId, $archivo->id, $user);
        }

        if ($request->hasFile('favicon')) {
            $archivo = $uploader->store($request->file('favicon'), $user, 'favicon');
            $configuration->set(ConfigurationKey::FaviconArchivoId, $archivo->id, $user);
        }

        if ($request->hasFile('pwaIcon')) {
            $archivo = $uploader->store($request->file('pwaIcon'), $user, 'pwa_icon');
            $configuration->set(ConfigurationKey::PwaIconArchivoId, $archivo->id, $user);
        }

        return back()->with('success', __('config.saved'));
    }
}
