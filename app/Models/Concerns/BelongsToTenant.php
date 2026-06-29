<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            if (TenantContext::shouldBypassScope()) {
                return;
            }

            $tenantId = TenantContext::id();

            if ($tenantId === null) {
                return;
            }

            $table = $builder->getModel()->getTable();

            if (static::tenantScopeIncludesGlobal()) {
                $builder->where(function (Builder $query) use ($table, $tenantId): void {
                    $query->whereNull("{$table}.tenant_id")
                        ->orWhere("{$table}.tenant_id", $tenantId);
                });
            } else {
                $builder->where("{$table}.tenant_id", $tenantId);
            }
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $tenantId = TenantContext::id();

            if ($tenantId !== null) {
                $model->setAttribute('tenant_id', $tenantId);
            }
        });
    }

    protected static function tenantScopeIncludesGlobal(): bool
    {
        return false;
    }
}
