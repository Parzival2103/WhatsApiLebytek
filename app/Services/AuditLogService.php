<?php

namespace App\Services;

use App\Models\Log\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogService
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'token',
        'secret',
        'credentials',
        'api_key',
        'email',
        'phone',
        'message',
        'content',
        'body',
    ];

    public function record(
        string $action,
        User $actor,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?Request $request = null,
    ): AuditLog {
        return AuditLog::create([
            'tenant_id' => $actor->tenant_id,
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'before' => $this->sanitize($before),
            'after' => $this->sanitize($after),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public function sanitize(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        return $this->sanitizeValue($payload);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sanitizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) && strlen($value) > 128
                ? '[redacted]'
                : $value;
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[redacted]';

                continue;
            }

            $sanitized[$key] = is_array($item)
                ? $this->sanitizeValue($item)
                : (is_string($item) && strlen($item) > 128 ? '[redacted]' : $item);
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
