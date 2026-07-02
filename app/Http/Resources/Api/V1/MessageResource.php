<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\ApiResource;
use App\Models\Integration\Mensaje;
use Illuminate\Http\Request;

/** @mixin Mensaje */
class MessageResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'direction' => $this->direction,
            'recipient' => $this->recipient,
            'body' => $this->body,
            'status' => $this->status,
            'error' => $this->error,
            'sentAt' => $this->sent_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
