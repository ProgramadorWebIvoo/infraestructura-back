<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora de todo archivo que pasa por FileIngestionPipeline (aceptado,
     * optimizado o rechazado) — separada de AuditLog porque esta última es
     * project_id-only y este pipeline también corre en contextos sin
     * proyecto (ej. portal público de proveedores). Es historial de
     * seguridad, no editable/borrable desde la app (solo INSERT).
     */
    public function up(): void
    {
        Schema::create('file_security_events', function (Blueprint $table) {
            $table->id();
            $table->string('context', 60);
            $table->string('context_id', 60)->nullable();
            $table->string('original_name', 255);
            $table->string('detected_mime', 120)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->enum('status', ['accepted', 'optimized', 'rejected'])->index();
            $table->string('reason', 255)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['context', 'context_id'], 'idx_file_security_events_context');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_security_events');
    }
};
