<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_tenants', function (Blueprint $table): void {
            $table->json('meta')->nullable()->after('first_message_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('core_tenants', function (Blueprint $table): void {
            $table->dropColumn('meta');
        });
    }
};
