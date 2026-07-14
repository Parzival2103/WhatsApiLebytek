<?php

namespace App\Services;

use App\Models\Core\Tenant;
use App\Support\PlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ActivatePlanService
{
    public function __construct(
        private readonly TenantTokenService $tenantTokenService,
    ) {}

    /**
     * @param  array{
     *   planSlug: string,
     *   billingCycle: string,
     *   orderExternalRef: string,
     *   messagesMonthlyLimit?: int|null,
     *   tokenName?: string|null
     * }  $data
     * @return array{
     *   tenant: Tenant,
     *   token: string|null,
     *   plan: array{slug: string, name: string, messagesMonthlyLimit: int|null, billingCycle: string},
     *   created: bool
     * }
     */
    public function activate(Tenant $tenant, array $data): array
    {
        $slug = $data['planSlug'];
        $definition = PlanCatalog::definition($slug);

        if ($definition === null) {
            throw ValidationException::withMessages([
                'planSlug' => ["Unknown planSlug [{$slug}]."],
            ]);
        }

        $orderRef = $data['orderExternalRef'];
        $meta = $tenant->meta ?? [];

        if (
            $tenant->commercial_status === 'active'
            && $tenant->plan_slug === $slug
            && ($meta['activated_order_ref'] ?? null) === $orderRef
        ) {
            return [
                'tenant' => $tenant,
                'token' => null,
                'plan' => [
                    'slug' => $slug,
                    'name' => $definition['name'],
                    'messagesMonthlyLimit' => $tenant->messages_monthly_limit,
                    'billingCycle' => (string) ($meta['billing_cycle'] ?? $data['billingCycle']),
                ],
                'created' => false,
            ];
        }

        try {
            $limit = PlanCatalog::resolveMessagesMonthlyLimit(
                $slug,
                isset($data['messagesMonthlyLimit']) ? (int) $data['messagesMonthlyLimit'] : null,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'messagesMonthlyLimit' => [$e->getMessage()],
            ]);
        }

        $tokenName = $data['tokenName'] ?? "cliente-{$slug}";
        $abilities = config('permissions.demo_client_abilities');

        return DB::transaction(function () use ($tenant, $slug, $definition, $limit, $data, $orderRef, $tokenName, $abilities): array {
            $tenant->forceFill([
                'commercial_status' => 'active',
                'plan_slug' => $slug,
                'plan_name' => $definition['name'],
                'messages_monthly_limit' => $limit,
                'demo_expires_at' => null,
                'meta' => array_merge($tenant->meta ?? [], [
                    'billing_cycle' => $data['billingCycle'],
                    'activated_order_ref' => $orderRef,
                    'activated_at' => now()->toIso8601String(),
                ]),
            ])->save();

            $this->tenantTokenService->revokeClientTokens($tenant);
            $accessToken = $this->tenantTokenService->issue($tenant, $tokenName, $abilities);

            return [
                'tenant' => $tenant->fresh(),
                'token' => $accessToken->plainTextToken,
                'plan' => [
                    'slug' => $slug,
                    'name' => $definition['name'],
                    'messagesMonthlyLimit' => $limit,
                    'billingCycle' => $data['billingCycle'],
                ],
                'created' => true,
            ];
        });
    }
}
