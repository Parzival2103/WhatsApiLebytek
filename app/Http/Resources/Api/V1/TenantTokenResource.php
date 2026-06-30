<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\ApiResource;
use Illuminate\Http\Request;
use Laravel\Sanctum\NewAccessToken;

class TenantTokenResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var NewAccessToken $token */
        $token = $this->resource;

        return [
            'publicId' => (string) $token->accessToken->getKey(),
            'token' => $token->plainTextToken,
            'name' => $token->accessToken->name,
            'abilities' => $token->accessToken->abilities ?? [],
            'createdAt' => $token->accessToken->created_at?->toIso8601String(),
        ];
    }
}
