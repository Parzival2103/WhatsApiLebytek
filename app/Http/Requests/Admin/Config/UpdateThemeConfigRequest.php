<?php

namespace App\Http\Requests\Admin\Config;

use Illuminate\Foundation\Http\FormRequest;

class UpdateThemeConfigRequest extends FormRequest
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
            'themeColors' => ['required', 'array'],
            'themeColors.primary' => ['required', 'string'],
            'themeColors.secondary' => ['required', 'string'],
            'themeColors.accent' => ['required', 'string'],
            'themeColors.background' => ['required', 'string'],
            'themeColors.foreground' => ['required', 'string'],
        ];
    }
}
