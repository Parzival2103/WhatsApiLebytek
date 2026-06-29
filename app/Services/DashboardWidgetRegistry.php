<?php

namespace App\Services;

use App\Contracts\DashboardWidget;
use App\Models\User;

class DashboardWidgetRegistry
{
    /**
     * @var list<class-string<DashboardWidget>>
     */
    private array $widgets = [];

    /**
     * @param  list<class-string<DashboardWidget>>  $widgets
     */
    public function register(array $widgets): void
    {
        foreach ($widgets as $widget) {
            $this->widgets[] = $widget;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user): array
    {
        return collect($this->widgets)
            ->map(fn (string $class) => app($class))
            ->filter(fn (DashboardWidget $widget) => $user->can($widget->permission()))
            ->map(fn (DashboardWidget $widget): array => [
                'key' => $widget->key(),
                'permission' => $widget->permission(),
                'component' => $widget->component(),
                'data' => $widget->data(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<class-string<DashboardWidget>>
     */
    public function registered(): array
    {
        return $this->widgets;
    }
}
