<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.webhooks.secret');

        if (empty($secret)) {
            abort(500, 'Webhook secret is not configured.');
        }

        $signature = $request->header('X-Webhook-Signature', '');

        if ($signature === '') {
            abort(401, 'Missing webhook signature.');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            abort(401, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
