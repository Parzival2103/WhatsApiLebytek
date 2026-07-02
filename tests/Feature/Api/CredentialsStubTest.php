<?php

use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('put credentials returns 501 not implemented', function () {
    $user = createPlatformServiceUser();
    $user->givePermissionTo('credenciales.gestionar');
    $token = $user->createToken('platform')->plainTextToken;

    $this->withToken($token)
        ->putJson(route('api.v1.credentials.green-api'), [
            'instanceId' => '110100',
            'apiTokenInstance' => 'secret',
        ], idempotencyHeaders())
        ->assertStatus(501)
        ->assertJsonPath('message', 'Not implemented');
});
