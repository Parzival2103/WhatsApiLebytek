<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Opt-in gate for expensive Live saturation tests.
 *
 * Soft-passes in CI / default `php artisan test` so the suite stays green
 * without burning demo message quota.
 */
final class SaturationGate
{
    public static function shouldRunLive(): bool
    {
        if (self::isCiEnvironment()) {
            return false;
        }

        return self::env('RUN_SATURATION_TESTS') === '1';
    }

    public static function softPass(): void
    {
        expect(true)->toBeTrue();
    }

    /**
     * @return array{
     *     token: string,
     *     baseUrl: string,
     *     instancePublicId: string,
     *     recipient: string
     * }
     */
    public static function resolveCredentials(): array
    {
        $token = self::env('SATURATION_DEMO_TOKEN');
        if ($token === null || $token === '') {
            $token = self::prompt('Pega el token Sanctum de la demo (Bearer): ');
        }

        if ($token === '') {
            throw new RuntimeException(
                'SATURATION_DEMO_TOKEN is required when RUN_SATURATION_TESTS=1. '.
                'Set the env var or paste the token at the prompt (TTY only).'
            );
        }

        $instancePublicId = self::env('SATURATION_INSTANCE_PUBLIC_ID')
            ?? self::prompt('instancePublicId de la instancia demo: ');
        if ($instancePublicId === '') {
            throw new RuntimeException('SATURATION_INSTANCE_PUBLIC_ID is required for Live saturation.');
        }

        $recipient = self::env('SATURATION_RECIPIENT')
            ?? self::prompt('Recipient E.164 (tu WhatsApp, digitos): ');
        if ($recipient === '' || ! preg_match('/^\d{8,15}$/', $recipient)) {
            throw new RuntimeException(
                'SATURATION_RECIPIENT must be E.164 digits only (e.g. 5215512345678).'
            );
        }

        $baseUrl = rtrim(self::env('SATURATION_API_BASE_URL') ?: 'https://api.lebytek.com', '/');
        if (! str_ends_with($baseUrl, '/api/v1')) {
            $baseUrl .= '/api/v1';
        }

        return [
            'token' => $token,
            'baseUrl' => $baseUrl,
            'instancePublicId' => $instancePublicId,
            'recipient' => $recipient,
        ];
    }

    private static function isCiEnvironment(): bool
    {
        foreach (['CI', 'GITHUB_ACTIONS', 'CONTINUOUS_INTEGRATION'] as $key) {
            $value = self::env($key);
            if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }

    private static function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return null;
        }

        return is_string($value) ? trim($value) : null;
    }

    private static function prompt(string $label): string
    {
        if (! self::isInteractive()) {
            return '';
        }

        fwrite(STDOUT, $label);
        $line = fgets(STDIN);

        return is_string($line) ? trim($line) : '';
    }

    private static function isInteractive(): bool
    {
        return defined('STDIN')
            && is_resource(STDIN)
            && function_exists('stream_isatty')
            && @stream_isatty(STDIN);
    }
}
