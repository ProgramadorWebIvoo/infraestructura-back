<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Claves de idempotencia de las mutaciones autenticadas (PLAN-Idempotencia).
 *
 * UNIQUE(user_id, key) es lo que hace atómico el "primero gana": el INSERT del
 * marcador `processing` falla para la segunda petición simultánea. `request_hash`
 * detecta una clave reutilizada con otro payload. `locked_until` permite retomar
 * un `processing` huérfano (proceso caído) sin bloquear la clave para siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('key', 36);
            $table->string('method', 10);
            $table->string('path', 255);
            $table->char('request_hash', 64);
            $table->string('status', 16)->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            // NULL en 204 y en respuestas que superaron el tope (response_omitted).
            $table->mediumText('response_body')->nullable();
            $table->boolean('response_omitted')->default(false);
            $table->json('response_headers')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            // idempotency:prune (completadas vencidas) y limpieza de huérfanas.
            $table->index(['status', 'locked_until']);
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
