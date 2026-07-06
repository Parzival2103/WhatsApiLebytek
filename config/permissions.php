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
        'cuenta.ver',
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
        'cuenta.ver',
        'credenciales.gestionar',
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo client API tokens (correo demo / api-client@tenants...)
    |--------------------------------------------------------------------------
    */
    'demo_client_abilities' => [
        'instancias.ver',
        'mensajes.enviar',
        'mensajes.ver',
        'cuenta.ver',
    ],
];
