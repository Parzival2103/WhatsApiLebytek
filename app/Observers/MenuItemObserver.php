<?php

namespace App\Observers;

use App\Models\Core\MenuItem;
use App\Services\AdminMenuService;

class MenuItemObserver
{
    public function saved(MenuItem $menuItem): void
    {
        app(AdminMenuService::class)->invalidateForTenant($menuItem->tenant_id);
    }

    public function deleted(MenuItem $menuItem): void
    {
        app(AdminMenuService::class)->invalidateForTenant($menuItem->tenant_id);
    }
}
