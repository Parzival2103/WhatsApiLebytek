<?php

namespace App\Services\GreenApi;

use App\Jobs\DeleteGreenInstanceJob;
use App\Jobs\ProvisionGreenInstanceJob;
use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

class InstanceProvisioningService
{
    public function __construct(
        private readonly WhatsappModuleGuard $moduleGuard,
    ) {}

    /**
     * @param  array{label: string, externalRef?: string|null, purpose?: string}  $data
     * @return array{instancia: Instancia, created: bool}
     */
    public function provision(int $tenantId, array $data): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $this->moduleGuard->ensureEnabled($tenant);

        $externalRef = $data['externalRef'] ?? null;

        if (is_string($externalRef) && $externalRef !== '') {
            $existing = Instancia::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('external_ref', $externalRef)
                ->first();

            if ($existing !== null) {
                return ['instancia' => $existing, 'created' => false];
            }
        }

        $instancia = DB::transaction(function () use ($tenantId, $data, $externalRef): Instancia {
            return Instancia::query()->create([
                'tenant_id' => $tenantId,
                'label' => $data['label'],
                'external_ref' => $externalRef,
                'purpose' => $data['purpose'] ?? 'demo',
                'status' => 'provisioning',
                'provider' => 'green_api',
            ]);
        });

        ProvisionGreenInstanceJob::dispatch($instancia->id);

        return ['instancia' => $instancia->fresh(), 'created' => true];
    }

    public function delete(Instancia $instancia): void
    {
        $this->moduleGuard->ensureEnabled($instancia->tenant);

        $instancia->update(['status' => 'deleting']);

        DeleteGreenInstanceJob::dispatch($instancia->id);
    }

    public function resolveActingTenantId(): int
    {
        $tenantId = TenantContext::id();

        if ($tenantId === null) {
            abort(400, 'X-Tenant-Id header required for this operation.');
        }

        return $tenantId;
    }

    public function findForActingTenant(string $publicId): Instancia
    {
        $tenantId = $this->resolveActingTenantId();

        return Instancia::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }
}
