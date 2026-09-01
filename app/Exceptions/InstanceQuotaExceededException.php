<?php

namespace App\Exceptions;

use RuntimeException;

class InstanceQuotaExceededException extends RuntimeException
{
    public const MESSAGE = 'Has alcanzado el límite de instancias WhatsApp de tu plan. Mejora tu cuenta para generar otra instancia.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
