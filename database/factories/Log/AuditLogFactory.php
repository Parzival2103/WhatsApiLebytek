<?php

namespace Database\Factories\Log;

use App\Models\Core\Tenant;
use App\Models\Log\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => null,
            'action' => fake()->word(),
            'subject_type' => null,
            'subject_id' => null,
            'before' => null,
            'after' => ['status' => 'updated'],
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'created_at' => now(),
        ];
    }
}
