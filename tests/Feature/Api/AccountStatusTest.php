<?php

use App\Models\Core\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('POST account status requires authentication', function () {
    $this->postJson(route('api.v1.account.status'))
        ->assertUnauthorized();
});

test('tenant token receives account status with demo quota fields', function () {
    $tenant = Tenant::factory()->create([
        'commercial_status' => 'demo',
        'plan_slug' => 'demo',
        'plan_name' => 'Demo',
        'demo_started_at' => now()->subDays(7),
        'demo_expires_at' => now()->addDays(23),
        'messages_monthly_limit' => 100,
    ]);

    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo(['cuenta.ver', 'instancias.ver']);
    $token = $client->createToken('client', ['cuenta.ver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson(route('api.v1.account.status'), []);

    $response->assertOk()
        ->assertJsonPath('commercialStatus', 'demo')
        ->assertJsonPath('plan.slug', 'demo')
        ->assertJsonPath('plan.name', 'Demo')
        ->assertJsonPath('plan.messagesPerMonthLimit', 100)
        ->assertJsonPath('usage.messagesLimitThisMonth', 100)
        ->assertJsonStructure([
            'requestedAt',
            'demo' => ['startedAt', 'expiresAt', 'daysRemaining', 'isExpired'],
            'usage' => ['messagesSentThisMonth', 'messagesRemainingThisMonth', 'messagesLimitThisMonth'],
        ]);

    expect($response->json('demo.daysRemaining'))->toBeGreaterThan(0);
    expect($response->json('usage.messagesRemainingThisMonth'))->toBe(100);
});

test('account status forbidden without cuenta.ver permission', function () {
    $tenant = Tenant::factory()->create();
    $client = User::factory()->forTenant($tenant)->create();
    $client->givePermissionTo(['instancias.ver']);
    $token = $client->createToken('client', ['instancias.ver'])->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.account.status'), [])
        ->assertForbidden();
});
