<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\ApiResource;
use Illuminate\Http\Request;

/** @mixin \App\Models\Core\Tenant */
class TenantResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'externalRef' => $this->external_ref,
            'isActive' => $this->is_active,
            'commercialStatus' => $this->commercial_status,
            'planSlug' => $this->plan_slug,
            'planName' => $this->plan_name,
            'demoStartedAt' => $this->demo_started_at?->toIso8601String(),
            'demoExpiresAt' => $this->demo_expires_at?->toIso8601String(),
            'messagesMonthlyLimit' => $this->messages_monthly_limit,
            'lastApiActivityAt' => $this->last_api_activity_at?->toIso8601String(),
            'firstMessageSentAt' => $this->first_message_sent_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
