<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('int_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type_webhook');
            $table->string('id_instance')->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('tenant_id')->nullable()->constrained('core_tenants')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'type_webhook']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('int_webhooks');
    }
};
