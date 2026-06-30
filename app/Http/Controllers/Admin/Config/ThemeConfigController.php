<?php

namespace App\Http\Controllers\Admin\Config;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Config\UpdateThemeConfigRequest;
use App\Services\ConfigurationService;
use App\Support\Config\ConfigurationKey;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ThemeConfigController extends Controller
{
    public function edit(ConfigurationService $configuration): Response
    {
        return Inertia::render('Admin/Config/Theme', [
            'themeColors' => $configuration->get(ConfigurationKey::ThemeColors),
        ]);
    }

    public function update(UpdateThemeConfigRequest $request, ConfigurationService $configuration): RedirectResponse
    {
        $configuration->set(
            ConfigurationKey::ThemeColors,
            $request->validated('themeColors'),
            $request->user(),
        );

        return back()->with('success', __('config.saved'));
    }
}
