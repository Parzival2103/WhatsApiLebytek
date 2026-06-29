<?php

use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CoreSeeder::class);
});

test('admin web login redirects to admin dashboard', function () {
    $this->post(route('admin.login.store'), [
        'email' => 'admin@sistema.local',
        'password' => 'password',
    ])->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticated();
});

test('guest is redirected from admin dashboard to login', function () {
    $this->get(route('admin.dashboard'))
        ->assertRedirect(route('login'));
});

test('authenticated admin reaches admin dashboard', function () {
    $admin = User::where('email', 'admin@sistema.local')->first();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk();
});
