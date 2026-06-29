<?php

use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CoreSeeder::class);
});

test('web admin route denies user without permission', function () {
    $user = User::factory()->create([
        'tenant_id' => \App\Models\Core\Tenant::where('slug', 'default')->value('id'),
    ]);

    $this->actingAs($user)
        ->get(route('admin.config.layout'))
        ->assertForbidden();
});

test('web admin route allows user with configuracion permission', function () {
    $admin = User::where('email', 'admin@sistema.local')->first();

    $this->actingAs($admin)
        ->get(route('admin.config.layout'))
        ->assertOk();
});
