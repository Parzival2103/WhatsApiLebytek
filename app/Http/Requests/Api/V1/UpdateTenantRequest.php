<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'isActive' => ['sometimes', 'boolean'],
            'commercialStatus' => ['prohibited'],
            'planSlug' => ['prohibited'],
            'planName' => ['prohibited'],
            'demoStartedAt' => ['prohibited'],
            'demoExpiresAt' => ['prohibited'],
            'messagesMonthlyLimit' => ['prohibited'],
        ];
    }
}
