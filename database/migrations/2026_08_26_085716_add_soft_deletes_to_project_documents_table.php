<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antes, ProjectDocumentController::destroy() borraba las filas físicamente
 * — un grupo eliminado no dejaba ningún rastro recuperable en BD, solo el
 * texto libre del AuditLog. Con soft-delete, la metadata (nombre, tipo,
 * quién, cuándo) sobrevive marcada con deleted_at, permitiendo mostrarla en
 * el historial completo de la tab "Archivos" — el archivo físico en disco
 * sigue eliminándose igual (libera espacio), solo la fila persiste.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('project_documents', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::table('project_documents', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
