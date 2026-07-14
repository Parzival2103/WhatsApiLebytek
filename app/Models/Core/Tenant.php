<?php

namespace App\Models\Core;

use Database\Factories\Core\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'slug', 'external_ref', 'is_active',
    'commercial_status', 'plan_slug', 'plan_name',
    'demo_started_at', 'demo_expires_at', 'messages_monthly_limit',
    'last_api_activity_at', 'first_message_sent_at', 'meta',
])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'core_tenants';

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            if (empty($tenant->public_id)) {
                $tenant->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'demo_started_at' => 'datetime',
            'demo_expires_at' => 'datetime',
            'last_api_activity_at' => 'datetime',
            'first_message_sent_at' => 'datetime',
            'messages_monthly_limit' => 'integer',
            'meta' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(\App\Models\User::class, 'tenant_id');
    }
}
