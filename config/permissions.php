<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Núcleo permissions (slug: modulo.accion)
    |--------------------------------------------------------------------------
    */
    'nucleo' => [
        'dashboard.ver',
        'configuracion.gestionar',
        'usuarios.gestionar',
        'modulos.gestionar',
        'bitacora.ver',
        'api.health',
        'tenants.ver',
        'tenants.provisionar',
        'tenants.gestionar',
    ],

    /*
    |--------------------------------------------------------------------------
    | waapi platform service (Sanctum guard)
    |--------------------------------------------------------------------------
    */
    'platform_service' => [
        'api.health',
        'tenants.ver',
        'tenants.provisionar',
        'tenants.gestionar',
    ],
];
