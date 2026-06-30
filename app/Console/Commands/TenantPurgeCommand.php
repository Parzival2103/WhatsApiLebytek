<?php

namespace App\Console\Commands;

use App\Models\Core\Tenant;
use Illuminate\Console\Command;

class TenantPurgeCommand extends Command
{
    protected $signature = 'tenant:purge {tenant : Tenant slug or public_id} {--force : Skip confirmation}';

    protected $description = 'Soft-delete a tenant and queue anonymization of tenant-scoped data';

    public function handle(): int
    {
        $identifier = $this->argument('tenant');

        $tenant = Tenant::query()
            ->where('slug', $identifier)
            ->orWhere('public_id', $identifier)
            ->first();

        if ($tenant === null) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Soft-delete tenant [{$tenant->name}]?")) {
            return self::SUCCESS;
        }

        $tenant->delete();

        // Vertical-specific purge jobs (campaigns, messages) register here.
        $this->info("Tenant [{$tenant->slug}] soft-deleted. Schedule anonymization jobs per vertical.");

        return self::SUCCESS;
    }
}
