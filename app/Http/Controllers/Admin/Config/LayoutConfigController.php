<?php

namespace App\Http\Controllers\Admin\Config;

use App\Http\Controllers\Controller;
use App\Services\ConfigurationService;
use App\Support\Config\ConfigurationKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LayoutConfigController extends Controller
{
    public function edit(ConfigurationService $configuration): Response
    {
        return Inertia::render('Admin/Config/Layout', [
            'layoutMode' => $configuration->get(ConfigurationKey::LayoutMode),
        ]);
    }

    public function update(Request $request, ConfigurationService $configuration): RedirectResponse
    {
        $validated = $request->validate([
            'layoutMode' => ['required', 'in:top,side'],
        ]);

        $configuration->set(
            ConfigurationKey::LayoutMode,
            $validated['layoutMode'],
            $request->user(),
        );

        return back()->with('success', __('config.saved'));
    }
}
