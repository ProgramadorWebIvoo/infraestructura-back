<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flujo de Marketing — separado del flujo de obra (`projects`): sin
     * relación con Project porque una pieza de marketing (impresión, vinil,
     * pendón, y a futuro cualquier otro material publicitario/corporativo)
     * no pertenece a un proyecto de infraestructura, tiene su propio ciclo
     * de vida (creación → revisión → aprobación/rechazo) y su propio dueño
     * (rol MARKETING). `type` es un enum abierto a extensión (ver
     * MarketingProject::TYPES) para no requerir una migración por cada
     * nuevo tipo de pieza que Marketing empiece a gestionar.
     */
    public function up()
    {
        Schema::create('marketing_projects', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('title', 220);
            $table->enum('type', ['IMPRESION', 'VINIL', 'PENDON', 'OTRO']);
            $table->text('description');
            $table->string('location', 180);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->decimal('estimated_cost', 14, 2)->nullable();
            $table->enum('priority', ['BAJA', 'MEDIA', 'ALTA'])->default('MEDIA');
            $table->enum('status', ['BORRADOR', 'EN_REVISION', 'APROBADO', 'RECHAZADO'])->default('BORRADOR');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status', 'idx_marketing_projects_status');
            $table->index('type', 'idx_marketing_projects_type');
            $table->index('requested_by', 'idx_marketing_projects_requested_by');
        });
    }

    public function down()
    {
        Schema::dropIfExists('marketing_projects');
    }
};
