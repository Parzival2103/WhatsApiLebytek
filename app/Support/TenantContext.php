<?php

namespace App\Support;

class TenantContext
{
    private static ?int $tenantId = null;

    private static bool $bypassScope = false;

    public static function set(?int $tenantId, bool $bypassScope = false): void
    {
        self::$tenantId = $tenantId;
        self::$bypassScope = $bypassScope;
    }

    public static function id(): ?int
    {
        return self::$tenantId;
    }

    public static function shouldBypassScope(): bool
    {
        return self::$bypassScope;
    }

    public static function clear(): void
    {
        self::$tenantId = null;
        self::$bypassScope = false;
    }
}
