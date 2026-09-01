<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_tenants', function (Blueprint $table) {
            $table->unsignedInteger('max_instances')->nullable()->after('messages_monthly_limit');
        });

        $catalog = config('plans.catalog', []);

        foreach ($catalog as $slug => $plan) {
            $max = $plan['max_instances'] ?? null;
            DB::table('core_tenants')
                ->where('plan_slug', $slug)
                ->update(['max_instances' => $max]);
        }

        // Tenants without plan_slug: treat as demo cupo
        DB::table('core_tenants')
            ->whereNull('plan_slug')
            ->orWhere('plan_slug', '')
            ->update(['max_instances' => $catalog['demo']['max_instances'] ?? 1]);
    }

    public function down(): void
    {
        Schema::table('core_tenants', function (Blueprint $table) {
            $table->dropColumn('max_instances');
        });
    }
};
