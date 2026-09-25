<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Destino del rechazo de Auditoría (F2-R R4a): CONTRATISTA o RESIDENTE. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_closure_reports', function (Blueprint $table) {
            $table->string('rejection_target', 20)->nullable()->after('rejected_by_role');
        });
    }

    public function down(): void
    {
        Schema::table('project_closure_reports', function (Blueprint $table) {
            $table->dropColumn('rejection_target');
        });
    }
};
