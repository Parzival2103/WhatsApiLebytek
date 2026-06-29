<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfg_configuraciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('core_tenants')->cascadeOnDelete();
            $table->string('key');
            $table->json('value');
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('cfg_catalogos_auxiliares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('core_tenants')->cascadeOnDelete();
            $table->string('catalog');
            $table->string('code');
            $table->string('label');
            $table->json('meta')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'catalog', 'code']);
        });

        Schema::create('log_bitacora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('core_tenants')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('core_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('core_tenants')->cascadeOnDelete();
            $table->string('module_key');
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'module_key']);
        });

        Schema::create('core_menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('core_tenants')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('core_menu_items')->nullOnDelete();
            $table->string('label');
            $table->string('route_name')->nullable();
            $table->string('permission')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'sort_order']);
        });

        Schema::create('core_archivos', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('core_tenants')->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('hash', 64);
            $table->string('purpose')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'purpose']);
        });

        Schema::create('int_credenciales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('core_tenants')->cascadeOnDelete();
            $table->string('provider');
            $table->text('credentials');
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
        });

        Schema::create('sys_kv', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_kv');
        Schema::dropIfExists('int_credenciales');
        Schema::dropIfExists('core_archivos');
        Schema::dropIfExists('core_menu_items');
        Schema::dropIfExists('core_modules');
        Schema::dropIfExists('log_bitacora');
        Schema::dropIfExists('cfg_catalogos_auxiliares');
        Schema::dropIfExists('cfg_configuraciones');
    }
};
