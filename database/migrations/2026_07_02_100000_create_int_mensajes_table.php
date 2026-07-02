<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('int_mensajes', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('core_tenants')->cascadeOnDelete();
            $table->foreignId('instancia_id')->nullable()->constrained('int_instancias')->nullOnDelete();
            $table->string('direction')->default('outbound');
            $table->string('recipient');
            $table->text('body');
            $table->string('status')->default('queued');
            $table->string('green_message_id')->nullable();
            $table->text('error')->nullable();
            $table->string('payload_hash')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'public_id']);
            $table->unique(['tenant_id', 'payload_hash']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('int_mensajes');
    }
};
