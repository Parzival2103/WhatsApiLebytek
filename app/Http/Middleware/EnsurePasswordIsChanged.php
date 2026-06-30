<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs('admin.password.change', 'admin.password.change.store', 'logout', 'admin.login', 'admin.login.store')) {
            return $next($request);
        }

        return redirect()->route('admin.password.change');
    }
}
