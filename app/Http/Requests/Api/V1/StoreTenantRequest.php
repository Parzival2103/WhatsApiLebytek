<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Core\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
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
        $existingTenantId = null;

        if ($this->filled('externalRef')) {
            $existingTenantId = Tenant::query()
                ->where('external_ref', $this->input('externalRef'))
                ->value('id');
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('core_tenants', 'slug')->ignore($existingTenantId),
            ],
            'externalRef' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('core_tenants', 'external_ref')->ignore($existingTenantId),
            ],
        ];
    }
}
