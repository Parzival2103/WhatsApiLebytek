<?php

namespace App\Dashboard\Widgets;

use App\Contracts\DashboardWidget;

class WelcomeWidget implements DashboardWidget
{
    public function key(): string
    {
        return 'welcome';
    }

    public function permission(): string
    {
        return 'dashboard.ver';
    }

    public function data(): array
    {
        return [
            'title' => 'Bienvenido',
            'message' => 'Panel administrativo del núcleo Lebytek.',
        ];
    }

    public function component(): ?string
    {
        return 'Admin/Dashboard/WelcomeWidget';
    }
}
