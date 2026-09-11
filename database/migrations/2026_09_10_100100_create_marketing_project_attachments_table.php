<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Imagenes/artes adjuntos a una pieza de marketing (mockups, referencias,
     * artes finales). Tabla separada de `marketing_projects` en vez de una
     * sola columna `image_path` — una pieza real suele tener varias
     * referencias/versiones de diseño (ver ProjectDocument, mismo patrón),
     * sin el versionado por grupo de ese modelo porque acá no aplica: cada
     * adjunto es independiente, no una nueva versión de uno anterior.
     */
    public function up()
    {
        Schema::create('marketing_project_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('marketing_project_id', 40);
            $table->string('original_name', 255);
            $table->string('stored_path', 500);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->foreign('marketing_project_id', 'fk_marketing_attachments_project')
                ->references('id')->on('marketing_projects')
                ->cascadeOnDelete();
            $table->index('marketing_project_id', 'idx_marketing_attachments_project');
        });
    }

    public function down()
    {
        Schema::dropIfExists('marketing_project_attachments');
    }
};
