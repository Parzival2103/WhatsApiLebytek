<?php

namespace App\Services;

use App\Models\Core\Module;
use App\Models\Core\Tenant;
use Database\Seeders\CoreSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantProvisioningService
{
    public function __construct(
        private readonly CoreSeeder $coreSeeder,
    ) {}

    /**
     * @param  array{name: string, slug: string, externalRef?: string|null}  $data
     * @return array{tenant: Tenant, created: bool}
     */
    public function provision(array $data): array
    {
        $externalRef = $data['externalRef'] ?? null;

        if (is_string($externalRef) && $externalRef !== '') {
            $existing = Tenant::query()->where('external_ref', $externalRef)->first();

            if ($existing !== null) {
                return ['tenant' => $existing, 'created' => false];
            }
        }

        $tenant = DB::transaction(function () use ($data, $externalRef): Tenant {
            $tenant = Tenant::query()->create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'external_ref' => $externalRef,
                'is_active' => true,
            ]);

            $this->coreSeeder->seedModulesAndMenu($tenant);

            Module::query()
                ->where('tenant_id', $tenant->id)
                ->where('module_key', 'whatsapp')
                ->update(['is_enabled' => true]);

            return $tenant->fresh();
        });

        return ['tenant' => $tenant, 'created' => true];
    }

    /**
     * @param  array{
     *     name?: string,
     *     isActive?: bool|null,
     *     commercialStatus?: string,
     *     planSlug?: string,
     *     planName?: string,
     *     demoStartedAt?: string,
     *     demoExpiresAt?: string,
     *     messagesMonthlyLimit?: int|null,
     * }  $data
     */
    public function update(Tenant $tenant, array $data): Tenant
    {
        $attributes = [];

        if (array_key_exists('name', $data) && is_string($data['name'])) {
            $attributes['name'] = $data['name'];
        }

        if (array_key_exists('isActive', $data)) {
            $attributes['is_active'] = (bool) $data['isActive'];
        }

        if (array_key_exists('commercialStatus', $data) && is_string($data['commercialStatus'])) {
            $attributes['commercial_status'] = $data['commercialStatus'];
        }

        if (array_key_exists('planSlug', $data) && is_string($data['planSlug'])) {
            $attributes['plan_slug'] = $data['planSlug'];
        }

        if (array_key_exists('planName', $data) && is_string($data['planName'])) {
            $attributes['plan_name'] = $data['planName'];
        }

        if (array_key_exists('demoStartedAt', $data) && is_string($data['demoStartedAt'])) {
            $attributes['demo_started_at'] = $data['demoStartedAt'];
        }

        if (array_key_exists('demoExpiresAt', $data) && is_string($data['demoExpiresAt'])) {
            $attributes['demo_expires_at'] = $data['demoExpiresAt'];
        }

        if (array_key_exists('messagesMonthlyLimit', $data)) {
            $attributes['messages_monthly_limit'] = $data['messagesMonthlyLimit'];
        }

        if ($attributes !== []) {
            $tenant->update($attributes);
        }

        return $tenant->fresh();
    }

    public function normalizeSlug(string $slug): string
    {
        return Str::slug($slug);
    }
}
