<?php

namespace App\Services;

use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use App\Services\GreenApi\InstanceStateSyncService;

final class DemoLeadAdminSnapshotService
{
    public function __construct(
        private readonly InstanceStateSyncService $stateSync,
    ) {}

    /**
     * @param  list<array{tenantPublicId: string, instancePublicId?: string|null}>  $items
     * @return array<string, array<string, mixed>>
     */
    public function buildSnapshotMap(array $items): array
    {
        $tenantIds = [];
        foreach ($items as $item) {
            $tenantPublicId = trim((string) ($item['tenantPublicId'] ?? ''));
            if ($tenantPublicId !== '') {
                $tenantIds[] = $tenantPublicId;
            }
        }

        if ($tenantIds === []) {
            return [];
        }

        $tenants = Tenant::query()
            ->whereIn('public_id', array_values(array_unique($tenantIds)))
            ->get()
            ->keyBy('public_id');

        $instanceIds = [];
        foreach ($items as $item) {
            $instancePublicId = trim((string) ($item['instancePublicId'] ?? ''));
            if ($instancePublicId !== '') {
                $instanceIds[] = $instancePublicId;
            }
        }

        $instances = $instanceIds === []
            ? collect()
            : Instancia::query()
                ->withoutGlobalScope('tenant')
                ->whereIn('public_id', array_values(array_unique($instanceIds)))
                ->get()
                ->keyBy('public_id');

        $map = [];

        foreach ($items as $item) {
            $tenantPublicId = trim((string) ($item['tenantPublicId'] ?? ''));
            if ($tenantPublicId === '') {
                continue;
            }

            /** @var Tenant|null $tenant */
            $tenant = $tenants->get($tenantPublicId);
            if ($tenant === null) {
                continue;
            }

            $snapshot = [
                'lastApiActivityAt' => $tenant->last_api_activity_at?->toIso8601String(),
            ];

            $instancePublicId = trim((string) ($item['instancePublicId'] ?? ''));
            if ($instancePublicId !== '') {
                /** @var Instancia|null $instancia */
                $instancia = $instances->get($instancePublicId);
                if ($instancia !== null && $instancia->tenant_id === $tenant->id) {
                    $instancia = $this->stateSync->refreshFromGreen($instancia);
                    $snapshot['instance'] = [
                        'publicId' => $instancia->public_id,
                        'status' => $instancia->status,
                        'greenState' => $instancia->green_state,
                        'lastError' => $instancia->last_error,
                    ];
                }
            }

            $map[$tenantPublicId] = $snapshot;
        }

        return $map;
    }
}
