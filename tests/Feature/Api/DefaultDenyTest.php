<?php

use App\Models\Core\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->tenant = Tenant::factory()->create();
});

test('default deny blocks api routes missing permission declaration', function () {
    $user = User::factory()->forTenant($this->tenant)->create();
    $token = $user->createToken('api-test')->plainTextToken;

    Route::middleware(['api', 'auth:sanctum', 'ensure.api.permission'])
        ->get('/api/v1/default-deny-probe', fn () => response()->json(['ok' => true]));

    $this->withToken($token)
        ->getJson('/api/v1/default-deny-probe')
        ->assertForbidden();
});
