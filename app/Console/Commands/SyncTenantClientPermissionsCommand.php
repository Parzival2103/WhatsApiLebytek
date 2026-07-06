<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;

class SyncTenantClientPermissionsCommand extends Command
{
    protected $signature = 'tenants:sync-client-permissions {--dry-run : List changes without applying}';

    protected $description = 'Sync Spatie permissions for api-client tenant users from token abilities + demo defaults';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $clients = User::query()
            ->where('email', 'like', 'api-client+%@tenants.lebytek.internal')
            ->get();

        if ($clients->isEmpty()) {
            $this->info('No api-client users found.');

            return self::SUCCESS;
        }

        foreach ($clients as $client) {
            $abilities = $this->resolveAbilitiesForUser($client);

            if ($abilities === []) {
                $this->warn("Skip {$client->email}: no token abilities found.");

                continue;
            }

            $this->line(sprintf(
                '%s → [%s]%s',
                $client->email,
                implode(', ', $abilities),
                $dryRun ? ' (dry-run)' : '',
            ));

            $permissions = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $abilities)
                ->get();

            $missing = array_values(array_diff($abilities, $permissions->pluck('name')->all()));
            if ($missing !== []) {
                $this->error(sprintf(
                    'Skip %s: missing permissions in DB [%s]. Run: php artisan db:seed --class=RolesAndPermissionsSeeder --force',
                    $client->email,
                    implode(', ', $missing),
                ));

                continue;
            }

            if ($dryRun) {
                continue;
            }

            $client->syncPermissions($permissions);
        }

        $this->info($dryRun ? 'Dry run complete.' : 'Permissions synced.');

        return self::SUCCESS;
    }

    /**
     * Union of all Sanctum token abilities plus demo defaults for api-client users.
     *
     * @return list<string>
     */
    private function resolveAbilitiesForUser(User $user): array
    {
        /** @var list<string> $fromTokens */
        $fromTokens = $user->tokens()
            ->get()
            ->flatMap(function (PersonalAccessToken $token): array {
                $abilities = $token->abilities ?? [];

                return array_values(array_filter(
                    is_array($abilities) ? $abilities : [],
                    fn (mixed $ability): bool => is_string($ability) && $ability !== '*',
                ));
            })
            ->unique()
            ->values()
            ->all();

        $demoDefaults = config('permissions.demo_client_abilities', [
            'instancias.ver',
            'mensajes.enviar',
            'mensajes.ver',
        ]);

        return array_values(array_unique(array_merge($fromTokens, $demoDefaults)));
    }
}
