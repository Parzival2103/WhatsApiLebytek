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
     * @return array{instancia: Instancia, created: bool, retried?: bool}
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
                if ($existing->status === 'failed') {
                    return [
                        'instancia' => $this->retryFailedInstance($existing),
                        'created' => false,
                        'retried' => true,
                    ];
                }

                return ['instancia' => $existing, 'created' => false, 'retried' => false];
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

        return ['instancia' => $instancia->fresh(), 'created' => true, 'retried' => false];
    }

    private function retryFailedInstance(Instancia $existing): Instancia
    {
        $lastError = strtolower((string) ($existing->last_error ?? ''));
        $greenCredentialsStale = $existing->id_instance === null
            || str_contains($lastError, 'delete')
            || str_contains($lastError, 'not found')
            || str_contains($lastError, 'createinstance');

        if ($greenCredentialsStale) {
            $existing->update([
                'status' => 'provisioning',
                'last_error' => null,
                'id_instance' => null,
                'api_token_instance' => null,
                'green_state' => null,
                'qr_code' => null,
                'qr_expires_at' => null,
                'authorized_at' => null,
            ]);
        } else {
            $existing->update([
                'status' => 'configuring',
                'last_error' => null,
            ]);
        }

        ProvisionGreenInstanceJob::dispatch($existing->id);

        return $existing->fresh() ?? $existing;
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
