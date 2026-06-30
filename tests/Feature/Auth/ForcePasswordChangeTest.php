<?php

use App\Models\User;
use Database\Seeders\CoreSeeder;
use Database\Seeders\ProductionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CoreSeeder::class);
});

test('user with must_change_password is redirected to change form', function () {
    $user = User::where('email', 'admin@sistema.local')->first();
    $user->update(['must_change_password' => true]);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('admin.password.change'));
});

test('user can change password and access dashboard', function () {
    $user = User::where('email', 'admin@sistema.local')->first();
    $user->update(['must_change_password' => true]);

    $this->actingAs($user)
        ->put(route('admin.password.change.store'), [
            'password' => 'NewSecurePass1!',
            'password_confirmation' => 'NewSecurePass1!',
        ])
        ->assertRedirect(route('admin.dashboard'));

    expect($user->fresh()->must_change_password)->toBeFalse();
});

test('production seeder requires admin initial password', function () {
    config(['nucleo.admin_initial_password' => null]);

    expect(fn () => $this->seed(ProductionSeeder::class))
        ->toThrow(RuntimeException::class);
});

test('production seeder creates admin with must change password', function () {
    config([
        'nucleo.admin_initial_email' => 'prod@example.com',
        'nucleo.admin_initial_password' => 'SecureProdPass1!',
    ]);

    $this->seed(ProductionSeeder::class);

    $admin = User::where('email', 'prod@example.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->must_change_password)->toBeTrue();
});
