<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\ApiResource;
use App\Models\Integration\Instancia;
use Illuminate\Http\Request;

/** @mixin Instancia */
class InstanceResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'label' => $this->label,
            'purpose' => $this->purpose,
            'status' => $this->status,
            'greenState' => $this->green_state,
            'idInstance' => $this->id_instance,
            'authorizedAt' => $this->authorized_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
