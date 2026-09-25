<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Propiedad de proyectos (F2-R R2): quien crea la solicitud es su propietario y
 * es el único usuario de INFRAESTRUCTURA que la ve. Las solicitudes previas
 * quedan sin propietario (invisibles para INFRAESTRUCTURA).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('requested_by_user_id')->nullable()->after('resident_user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by_user_id');
        });
    }
};
