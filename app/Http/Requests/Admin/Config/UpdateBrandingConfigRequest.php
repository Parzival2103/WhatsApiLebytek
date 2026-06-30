<?php

namespace App\Http\Requests\Admin\Config;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandingConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('configuracion.gestionar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'appName' => ['required', 'string', 'max:120'],
            'pwaThemeColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'pwaBackgroundColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'file', 'max:5120'],
            'favicon' => ['nullable', 'file', 'max:5120'],
            'pwaIcon' => ['nullable', 'file', 'max:5120'],
        ];
    }
}
