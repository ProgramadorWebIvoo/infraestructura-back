<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('project_id', 40);
            $table->string('project_title_snapshot', 220);
            $table->enum('role', ['PRESIDENCIA', 'INFRAESTRUCTURA', 'CIERRE_DE_OBRA', 'PROCURA', 'ANALISTA', 'FINANZAS', 'SISTEMA']);
            $table->string('action', 180);
            $table->dateTime('logged_at');
            $table->text('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('project_id', 'idx_audit_logs_project');
            $table->index('role', 'idx_audit_logs_role');
            $table->index('logged_at', 'idx_audit_logs_logged_at');

            $table->foreign('project_id', 'fk_audit_logs_project')
                ->references('id')->on('projects')
                ->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('audit_logs');
    }
};
