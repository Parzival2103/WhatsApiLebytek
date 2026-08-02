<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class DemoLeadSnapshotBatchRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.tenantPublicId' => ['required', 'string', 'size:26'],
            'items.*.instancePublicId' => ['nullable', 'string', 'size:26'],
        ];
    }
}
