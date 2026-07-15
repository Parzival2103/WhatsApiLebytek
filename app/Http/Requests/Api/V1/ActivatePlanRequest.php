<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivatePlanRequest extends FormRequest
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
        $slugs = array_keys(config('plans.catalog', []));

        return [
            'planSlug' => ['required', 'string', Rule::in($slugs)],
            'billingCycle' => ['required', 'string', Rule::in(['monthly', 'annual'])],
            'orderExternalRef' => ['required', 'string', 'max:100'],
            'messagesMonthlyLimit' => [
                'nullable',
                'integer',
                'prohibited_unless:planSlug,empresa',
                'required_if:planSlug,empresa',
                'min:'.(int) config('plans.empresa.messages_monthly_limit_min', 1000),
                'max:'.(int) config('plans.empresa.messages_monthly_limit_max', 10_000_000),
            ],
            'tokenName' => ['nullable', 'string', 'max:100'],
        ];
    }
}
