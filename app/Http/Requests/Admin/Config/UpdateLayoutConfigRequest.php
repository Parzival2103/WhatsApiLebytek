<?php

namespace App\Http\Requests\Admin\Config;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLayoutConfigRequest extends FormRequest
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
            'layoutMode' => ['required', 'in:top,side'],
        ];
    }
}
