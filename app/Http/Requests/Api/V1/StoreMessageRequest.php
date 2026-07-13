<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Messaging\RecipientNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

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
            'recipient' => ['required', 'string', 'max:48'],
            'body' => ['required', 'string', 'max:4096'],
            'instancePublicId' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $raw = (string) $this->input('recipient', '');

            try {
                app(RecipientNormalizer::class)->normalize($raw);
            } catch (InvalidArgumentException) {
                $validator->errors()->add(
                    'recipient',
                    'The recipient must be an E.164 phone number (digits) or a WhatsApp group id ending in @g.us.',
                );
            }
        });
    }
}
