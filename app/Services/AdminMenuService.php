<?php

namespace App\Services;

use App\Models\Core\MenuItem;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AdminMenuService
{
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user): array
    {
        $cacheKey = $this->cacheKey($user);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($user) {
            return $this->buildTree(
                $this->visibleItems($user),
            );
        });
    }

    public function forgetForUser(User $user): void
    {
        Cache::forget($this->cacheKey($user));
    }

    public function invalidateForTenant(?int $tenantId): void
    {
        $key = 'admin_menu_version:'.($tenantId ?? 'platform');
        Cache::put($key, (int) Cache::get($key, 0) + 1);
    }

    /**
     * @return Collection<int, MenuItem>
     */
    private function visibleItems(User $user): Collection
    {
        $previousContext = [TenantContext::id(), TenantContext::shouldBypassScope()];

        if ($user->tenant_id !== null) {
            TenantContext::set($user->tenant_id, $user->isPlatformAdmin());
        } elseif ($user->isPlatformAdmin()) {
            TenantContext::set(null, true);
        }

        try {
            $items = MenuItem::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        } finally {
            TenantContext::set($previousContext[0], $previousContext[1]);
        }

        return $items->filter(function (MenuItem $item) use ($user): bool {
            if ($item->permission === null) {
                return true;
            }

            return $user->can($item->permission);
        })->values();
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     * @return list<array<string, mixed>>
     */
    private function buildTree(Collection $items, ?int $parentId = null): array
    {
        return $items
            ->where('parent_id', $parentId)
            ->sortBy('sort_order')
            ->values()
            ->map(function (MenuItem $item) use ($items): array {
                $children = $this->buildTree($items, $item->id);

                return [
                    'id' => $item->id,
                    'label' => $item->label,
                    'routeName' => $item->route_name,
                    'icon' => $item->icon,
                    'children' => $children,
                ];
            })
            ->filter(function (array $node): bool {
                return $node['routeName'] !== null || count($node['children']) > 0;
            })
            ->values()
            ->all();
    }

    private function cacheKey(User $user): string
    {
        $roleKey = $user->getRoleNames()->sort()->implode('|');
        $tenantId = $user->tenant_id ?? 'platform';
        $version = Cache::get('admin_menu_version:'.$tenantId, 1);

        return "admin_menu:{$tenantId}:{$version}:{$roleKey}";
    }
}
