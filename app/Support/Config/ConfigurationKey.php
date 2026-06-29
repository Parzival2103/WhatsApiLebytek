<?php

namespace App\Support\Config;

enum ConfigurationKey: string
{
    case LayoutMode = 'layout.mode';
    case ThemeColors = 'theme.colors';
    case AppName = 'app.name';
    case PwaThemeColor = 'pwa.theme_color';
    case PwaBackgroundColor = 'pwa.background_color';
    case LogoArchivoId = 'branding.logo_archivo_id';
    case FaviconArchivoId = 'branding.favicon_archivo_id';
    case PwaIconArchivoId = 'branding.pwa_icon_archivo_id';
}
