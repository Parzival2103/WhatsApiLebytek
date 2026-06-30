<?php

namespace App\Services;

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

            return $tenant->fresh();
        });

        return ['tenant' => $tenant, 'created' => true];
    }

    /**
     * @param  array{name?: string, isActive?: bool|null}  $data
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
