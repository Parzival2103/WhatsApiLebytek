<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('int_instancias', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('core_tenants')->cascadeOnDelete();
            $table->string('id_instance')->nullable()->unique();
            $table->text('api_token_instance')->nullable();
            $table->string('provider')->default('green_api');
            $table->string('label');
            $table->string('purpose')->default('demo');
            $table->string('status')->default('provisioning');
            $table->string('green_state')->nullable();
            $table->string('external_ref')->nullable();
            $table->text('qr_code')->nullable();
            $table->timestamp('qr_expires_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'external_ref']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('int_instancias');
    }
};
