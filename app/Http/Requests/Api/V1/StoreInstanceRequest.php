<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Core\Tenant;
use App\Models\Integration\Instancia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInstanceRequest extends FormRequest
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
        $tenantId = $this->user()?->tenant_id ?? $this->actingTenantId();

        $existingId = null;

        if ($this->filled('externalRef') && $tenantId !== null) {
            $existingId = Instancia::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('external_ref', $this->input('externalRef'))
                ->value('id');
        }

        return [
            'label' => ['required', 'string', 'max:255'],
            'externalRef' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('int_instancias', 'external_ref')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($existingId),
            ],
            'purpose' => ['nullable', 'string', Rule::in(['demo', 'production'])],
        ];
    }

    private function actingTenantId(): ?int
    {
        $publicId = $this->header('X-Tenant-Id');

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        return Tenant::query()->where('public_id', $publicId)->value('id');
    }
}
