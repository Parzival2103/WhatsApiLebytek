<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\WaapiServiceSeeder;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;

class IssueWaapiTokenCommand extends Command
{
    protected $signature = 'integration:issue-waapi-token
                            {--name=waapi-service : Token name}
                            {--revoke : Revoke existing tokens before issuing a new one}';

    protected $description = 'Issue a Sanctum token for the waapi platform service account';

    public function handle(): int
    {
        $this->call(WaapiServiceSeeder::class);

        $email = config('nucleo.waapi_service_email');
        $serviceUser = User::query()->where('email', $email)->firstOrFail();

        if ($this->option('revoke')) {
            PersonalAccessToken::query()
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $serviceUser->id)
                ->delete();

            $this->info('Existing waapi service tokens revoked.');
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $platformPermissions = config('permissions.platform_service', []);
        $this->line('Platform permissions: '.implode(', ', $platformPermissions));
        $this->line('Assigned: '.$serviceUser->getAllPermissions()->pluck('name')->sort()->implode(', '));

        $token = $serviceUser->createToken($this->option('name'));

        $this->newLine();
        $this->line('Copy this token into lebytek.com .env as LEBYTEK_API_TOKEN:');
        $this->newLine();
        $this->warn($token->plainTextToken);
        $this->newLine();
        $this->comment('This token is shown once. Store it securely.');

        return self::SUCCESS;
    }
}
