<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SetTenantContext::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(append: [
            \App\Http\Middleware\SetTenantContext::class,
            \App\Http\Middleware\ResolveActingTenant::class,
        ]);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'ensure.api.permission' => \App\Http\Middleware\EnsureApiPermission::class,
            'webhook.signature' => \App\Http\Middleware\VerifyWebhookSignature::class,
            'password.changed' => \App\Http\Middleware\EnsurePasswordIsChanged::class,
            'api.idempotency' => \App\Http\Middleware\ApiIdempotencyKey::class,
            'track.tenant.activity' => \App\Http\Middleware\TrackTenantActivity::class,
            'acting.tenant' => \App\Http\Middleware\ResolveActingTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Route model binding becomes NotFoundHttpException after prepareException;
        // sanitize so API clients never see Eloquent FQCNs.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if (! $e->getPrevious() instanceof ModelNotFoundException) {
                return null;
            }

            return response()->json(['message' => 'Resource not found.'], 404);
        });
    })->create();
