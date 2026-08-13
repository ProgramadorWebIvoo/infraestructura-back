<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `audit_logs.role` era un ENUM con solo 7 valores — le faltaban
     * SUPERADMIN, ADMIN y CATALOGOS (el conjunto real de roles vive en
     * App\Support\Roles::VALID, 9 valores). `users.role` ya es un string
     * libre sin ENUM; mantener un ENUM paralelo en audit_logs garantiza que
     * vuelvan a divergir con cada rol nuevo, y cada uno exigiría un
     * ALTER TABLE con lock. La validación pasa a la capa de aplicación
     * (Roles::VALID), donde ya vive para el resto del sistema.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('role', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->enum('role', ['PRESIDENCIA', 'INFRAESTRUCTURA', 'CIERRE_DE_OBRA', 'PROCURA', 'ANALISTA', 'FINANZAS', 'SISTEMA'])->change();
        });
    }
};
