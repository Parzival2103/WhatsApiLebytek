<?php

namespace App\Http\Controllers\Admin\Config;

use App\Http\Controllers\Controller;
use App\Services\ConfigurationService;
use App\Support\Config\ConfigurationKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function update(Request $request, ConfigurationService $configuration): RedirectResponse
    {
        $validated = $request->validate([
            'themeColors' => ['required', 'array'],
            'themeColors.primary' => ['required', 'string'],
            'themeColors.secondary' => ['required', 'string'],
            'themeColors.accent' => ['required', 'string'],
            'themeColors.background' => ['required', 'string'],
            'themeColors.foreground' => ['required', 'string'],
        ]);

        $configuration->set(
            ConfigurationKey::ThemeColors,
            $validated['themeColors'],
            $request->user(),
        );

        return back()->with('success', __('config.saved'));
    }
}
