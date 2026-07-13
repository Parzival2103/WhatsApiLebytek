<?php

namespace App\Services\Messaging;

use InvalidArgumentException;

class RecipientNormalizer
{
    private const GROUP_PATTERN = '/^\d{10,32}@g\.us$/';

    public function normalize(string $raw): string
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Recipient is required.');
        }

        if (str_contains($trimmed, '@')) {
            if (preg_match(self::GROUP_PATTERN, $trimmed) !== 1) {
                throw new InvalidArgumentException('Invalid group recipient.');
            }

            return $trimmed;
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if (strlen($digits) < 10 || strlen($digits) > 15) {
            throw new InvalidArgumentException('Invalid phone recipient.');
        }

        return $digits;
    }
}
