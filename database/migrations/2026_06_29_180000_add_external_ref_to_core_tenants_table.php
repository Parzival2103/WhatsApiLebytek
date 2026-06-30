<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_tenants', function (Blueprint $table) {
            $table->string('external_ref')->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('core_tenants', function (Blueprint $table) {
            $table->dropColumn('external_ref');
        });
    }
};
