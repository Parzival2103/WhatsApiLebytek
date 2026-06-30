<?php

namespace App\Http\Controllers\Admin\Config;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Config\UpdateLayoutConfigRequest;
use App\Services\ConfigurationService;
use App\Support\Config\ConfigurationKey;
use Illuminate\Http\RedirectResponse;
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

    public function update(UpdateLayoutConfigRequest $request, ConfigurationService $configuration): RedirectResponse
    {
        $configuration->set(
            ConfigurationKey::LayoutMode,
            $request->validated('layoutMode'),
            $request->user(),
        );

        return back()->with('success', __('config.saved'));
    }
}
