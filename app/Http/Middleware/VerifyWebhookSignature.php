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

        if (! is_string($secret) || $secret === '') {
            abort(500, 'Webhook secret is not configured.');
        }

        $signature = $request->header('X-Webhook-Signature', '');

        if (is_string($signature) && $signature !== '') {
            $expected = hash_hmac('sha256', $request->getContent(), $secret);

            if (! hash_equals($expected, $signature)) {
                abort(401, 'Invalid webhook signature.');
            }

            $request->attributes->set('webhook_auth_mode', 'hmac');

            return $next($request);
        }

        $authorization = $request->header('Authorization', '');

        if (is_string($authorization) && preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches) === 1) {
            $token = $matches[1];

            if (hash_equals($secret, $token)) {
                $request->attributes->set('webhook_auth_mode', 'bearer');

                return $next($request);
            }

            abort(401, 'Invalid webhook bearer token.');
        }

        abort(401, 'Missing webhook authentication.');
    }
}
