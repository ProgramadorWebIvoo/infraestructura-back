<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('title', 220);
            $table->enum('type', ['INFRAESTRUCTURA', 'MANTENIMIENTO']);
            $table->text('description');
            $table->string('location', 180);
            $table->date('created_date');
            $table->enum('status', [
                'CREADO',
                'REVISADO_CIERRE',
                'CONFIRMADO_PROCURA',
                'COMPARATIVA_ENVIADA',
                'CONTRATADO',
                'EN_EJECUCION',
                'VERIFICANDO_FINALIZACION',
                'LISTO_PAGO_FINAL',
                'COMPLETADO_PAGADO',
            ])->default('CREADO');
            $table->decimal('estimated_total', 14, 2)->default(0.00);
            $table->text('cierre_obra_notes')->nullable();
            $table->boolean('calculations_added')->default(false);
            $table->unsignedInteger('blueprints_count')->default(0);
            $table->text('procura_review_notes')->nullable();
            $table->decimal('approved_investment_amount', 14, 2)->nullable();
            $table->string('selected_contractor_code', 30)->nullable();
            $table->string('selected_proposal_id', 40)->nullable();
            $table->boolean('quality_verified')->default(false);
            $table->date('completion_verified_date')->nullable();
            $table->timestamps();

            $table->index('status', 'idx_projects_status');
            $table->index('type', 'idx_projects_type');
            $table->index('created_date', 'idx_projects_created_date');
            $table->index('selected_contractor_code', 'idx_projects_selected_contractor');
            $table->index('selected_proposal_id', 'fk_projects_selected_proposal');

            $table->foreign('selected_contractor_code', 'fk_projects_selected_contractor')
                ->references('code')->on('contractors')
                ->onDelete('set null')->onUpdate('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('projects');
    }
};
