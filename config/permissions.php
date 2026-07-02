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
        'instancias.ver',
        'instancias.crear',
        'instancias.eliminar',
        'mensajes.enviar',
        'mensajes.ver',
        'credenciales.gestionar',
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform service (Sanctum guard) — back-office lebytek.com
    |--------------------------------------------------------------------------
    */
    'platform_service' => [
        'api.health',
        'tenants.ver',
        'tenants.provisionar',
        'tenants.gestionar',
        'instancias.ver',
        'instancias.crear',
        'instancias.eliminar',
        'mensajes.enviar',
        'mensajes.ver',
        'credenciales.gestionar',
    ],
];
