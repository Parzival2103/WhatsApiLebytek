<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Dashboard');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Services');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Queue');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit/Jobs');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function createPlatformServiceUser(): \App\Models\User
{
    $permissions = config('permissions.platform_service', []);

    $user = \App\Models\User::factory()->platformAdmin()->create([
        'email' => 'waapi-service@test.local',
    ]);

    $user->syncPermissions(
        \Spatie\Permission\Models\Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $permissions)
            ->get()
    );

    return $user;
}

function platformServiceToken(?\App\Models\User $user = null): string
{
    $user ??= createPlatformServiceUser();

    return $user->createToken('waapi-test')->plainTextToken;
}

function idempotencyHeaders(): array
{
    return [
        'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(),
    ];
}
