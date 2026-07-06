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
            'commercialStatus' => ['sometimes', 'string', 'in:demo,active,past_due,cancelled'],
            'planSlug' => ['sometimes', 'string', 'max:50'],
            'planName' => ['sometimes', 'string', 'max:150'],
            'demoStartedAt' => ['sometimes', 'date'],
            'demoExpiresAt' => ['sometimes', 'date'],
            'messagesMonthlyLimit' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
