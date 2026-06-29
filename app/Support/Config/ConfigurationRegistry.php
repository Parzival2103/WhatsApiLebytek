<?php

namespace App\Support\Config;

use InvalidArgumentException;

class ConfigurationRegistry
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            ConfigurationKey::LayoutMode->value => 'side',
            ConfigurationKey::ThemeColors->value => [
                'primary' => '#2563eb',
                'secondary' => '#64748b',
                'accent' => '#0ea5e9',
                'background' => '#ffffff',
                'foreground' => '#0f172a',
            ],
            ConfigurationKey::AppName->value => config('app.name', 'Lebytek'),
            ConfigurationKey::PwaThemeColor->value => '#2563eb',
            ConfigurationKey::PwaBackgroundColor->value => '#ffffff',
            ConfigurationKey::LogoArchivoId->value => null,
            ConfigurationKey::FaviconArchivoId->value => null,
            ConfigurationKey::PwaIconArchivoId->value => null,
        ];
    }

    public static function default(ConfigurationKey $key): mixed
    {
        return self::defaults()[$key->value] ?? null;
    }

    public static function validate(ConfigurationKey $key, mixed $value): mixed
    {
        return match ($key) {
            ConfigurationKey::LayoutMode => self::validateLayoutMode($value),
            ConfigurationKey::ThemeColors => self::validateThemeColors($value),
            ConfigurationKey::AppName => self::validateAppName($value),
            ConfigurationKey::PwaThemeColor,
            ConfigurationKey::PwaBackgroundColor => self::validateHexColor($value),
            ConfigurationKey::LogoArchivoId,
            ConfigurationKey::FaviconArchivoId,
            ConfigurationKey::PwaIconArchivoId => self::validateNullableInt($value),
        };
    }

    private static function validateLayoutMode(mixed $value): string
    {
        if (! in_array($value, ['top', 'side'], true)) {
            throw new InvalidArgumentException('layout.mode must be top or side');
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function validateThemeColors(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('theme.colors must be an array');
        }

        return $value;
    }

    private static function validateAppName(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('app.name must be a non-empty string');
        }

        return trim($value);
    }

    private static function validateHexColor(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            throw new InvalidArgumentException('Color must be a hex value like #2563eb');
        }

        return $value;
    }

    private static function validateNullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException('Archivo id must be an integer or null');
        }

        return (int) $value;
    }
}
