<?php

use App\Dashboard\Widgets\SystemStatusWidget;
use App\Dashboard\Widgets\WelcomeWidget;

return [
    'widgets' => [
        WelcomeWidget::class,
        SystemStatusWidget::class,
    ],
];
