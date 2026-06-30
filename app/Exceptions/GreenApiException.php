<?php

namespace App\Exceptions;

use Exception;

class GreenApiException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly ?array $response = null,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function response(): ?array
    {
        return $this->response;
    }
}
