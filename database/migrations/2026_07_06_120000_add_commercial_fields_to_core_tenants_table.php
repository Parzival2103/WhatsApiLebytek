<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_tenants', function (Blueprint $table) {
            $table->string('commercial_status', 30)->default('demo')->after('is_active');
            $table->string('plan_slug', 50)->nullable()->after('commercial_status');
            $table->string('plan_name', 150)->nullable()->after('plan_slug');
            $table->timestamp('demo_started_at')->nullable()->after('plan_name');
            $table->timestamp('demo_expires_at')->nullable()->after('demo_started_at');
            $table->unsignedInteger('messages_monthly_limit')->nullable()->after('demo_expires_at');
            $table->timestamp('last_api_activity_at')->nullable()->after('messages_monthly_limit');
            $table->timestamp('first_message_sent_at')->nullable()->after('last_api_activity_at');

            $table->index('commercial_status');
            $table->index('demo_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('core_tenants', function (Blueprint $table) {
            $table->dropIndex(['commercial_status']);
            $table->dropIndex(['demo_expires_at']);
            $table->dropColumn([
                'commercial_status',
                'plan_slug',
                'plan_name',
                'demo_started_at',
                'demo_expires_at',
                'messages_monthly_limit',
                'last_api_activity_at',
                'first_message_sent_at',
            ]);
        });
    }
};
