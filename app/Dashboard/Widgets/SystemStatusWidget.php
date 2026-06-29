<?php

namespace App\Dashboard\Widgets;

use App\Contracts\DashboardWidget;

class SystemStatusWidget implements DashboardWidget
{
    public function key(): string
    {
        return 'system-status';
    }

    public function permission(): string
    {
        return 'modulos.gestionar';
    }

    public function data(): array
    {
        return [
            'title' => 'Estado del sistema',
            'status' => 'ok',
        ];
    }

    public function component(): ?string
    {
        return 'Admin/Dashboard/SystemStatusWidget';
    }
}
