<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ForcePasswordChangeRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ForcePasswordChangeController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Admin/Auth/ForcePasswordChange');
    }

    public function update(ForcePasswordChangeRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => $request->validated('password'),
            'must_change_password' => false,
        ])->save();

        return redirect()->route('admin.dashboard')->with('success', __('auth.password_changed'));
    }
}
