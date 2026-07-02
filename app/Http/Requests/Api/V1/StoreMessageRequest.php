<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'max:32'],
            'body' => ['required', 'string', 'max:4096'],
            'instancePublicId' => ['required', 'string'],
        ];
    }
}
