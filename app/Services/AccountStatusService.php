<?php

namespace App\Services;

use App\Models\Core\Tenant;
use App\Models\Integration\Mensaje;
use Illuminate\Support\Carbon;

class AccountStatusService
{
    /**
     * @return array<string, mixed>
     */
    public function buildStatus(Tenant $tenant): array
    {
        $now = now();
        $messagesSent = $this->countMessagesSentThisMonth($tenant->id);
        $limit = $tenant->messages_monthly_limit;
        $messagesRemaining = $limit !== null ? max(0, $limit - $messagesSent) : null;

        $daysRemaining = null;
        $isExpired = false;
        if ($tenant->demo_expires_at !== null) {
            $daysRemaining = max(0, (int) $now->diffInDays($tenant->demo_expires_at, false));
            $isExpired = $tenant->demo_expires_at->isPast();
        }

        return [
            'requestedAt' => $now->toIso8601String(),
            'commercialStatus' => $tenant->commercial_status ?? 'demo',
            'plan' => [
                'slug' => $tenant->plan_slug,
                'name' => $tenant->plan_name,
                'messagesPerMonthLimit' => $limit,
            ],
            'demo' => [
                'startedAt' => $tenant->demo_started_at?->toIso8601String(),
                'expiresAt' => $tenant->demo_expires_at?->toIso8601String(),
                'daysRemaining' => $daysRemaining,
                'isExpired' => $isExpired,
            ],
            'usage' => [
                'messagesSentThisMonth' => $messagesSent,
                'messagesRemainingThisMonth' => $messagesRemaining,
                'messagesLimitThisMonth' => $limit,
            ],
        ];
    }

    public function countMessagesSentThisMonth(int $tenantId): int
    {
        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();

        return Mensaje::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('direction', 'outbound')
            ->whereIn('status', ['queued', 'sent'])
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    public function touchActivity(Tenant $tenant): void
    {
        $tenant->forceFill(['last_api_activity_at' => now()])->save();
    }

    public function recordFirstMessage(Tenant $tenant): void
    {
        if ($tenant->first_message_sent_at === null) {
            $tenant->forceFill(['first_message_sent_at' => now()])->save();
        }
    }
}
