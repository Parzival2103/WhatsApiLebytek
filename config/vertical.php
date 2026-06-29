<?php

return [
    /*
    | Modules available in code. Authoritative on/off state lives in core_modules (DB).
    */
    'modules' => [
        'core' => [
            'name' => 'Núcleo',
            'description' => 'Administración base de la plataforma',
        ],
        'whatsapp' => [
            'name' => 'WhatsApp API',
            'description' => 'Integración Green API, colas y campañas',
        ],
    ],
];
