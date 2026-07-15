<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
                $this->reject($request, 'hmac', 'invalid_signature', 'Invalid webhook signature.');
            }

            return $this->accept($request, $next, 'hmac');
        }

        $authorization = $request->header('Authorization', '');
        $authorization = is_string($authorization) ? trim($authorization) : '';

        if ($authorization === '') {
            $this->reject($request, 'none', 'missing_credentials', 'Missing webhook authentication.');
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches) !== 1) {
            $this->reject($request, 'none', 'unsupported_authorization', 'Unsupported webhook authorization header.');
        }

        if (! hash_equals($secret, $matches[1])) {
            $this->reject($request, 'bearer', 'invalid_bearer_token', 'Invalid webhook bearer token.');
        }

        return $this->accept($request, $next, 'bearer');
    }

    private function accept(Request $request, Closure $next, string $mode): Response
    {
        $request->attributes->set('webhook_auth_mode', $mode);

        Log::info('Webhook authenticated.', [
            'webhook_auth_mode' => $mode,
            'path' => $request->path(),
        ]);

        return $next($request);
    }

    private function reject(Request $request, string $mode, string $reason, string $message): never
    {
        Log::warning('Webhook authentication rejected.', [
            'webhook_auth_mode' => $mode,
            'reason' => $reason,
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        abort(401, $message);
    }
}
