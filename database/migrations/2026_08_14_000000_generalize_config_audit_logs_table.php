<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `config_audit_logs` deja de ser exclusivamente "cambios de AppSetting"
     * y pasa a cubrir cualquier acción administrativa que le compete a
     * SUPERADMIN/ADMIN — gestión de usuarios, proveedores, materiales,
     * configuración de IA, y la futura matriz de notificaciones — sin crear
     * una tercera tabla de auditoría (decisión explícita: solo existen dos
     * audiencias, `audit_logs` para el flujo regular que ve Presidencia, y
     * `config_audit_logs` para lo administrativo).
     *
     * `entity_type` distingue el tipo de fila ('setting' para lo que ya
     * existía, o 'user'/'contractor'/'material'/'ai_config'/
     * 'notification_rule' para lo nuevo). `action` es el nombre legible de
     * la operación (reutiliza los strings de NotificationCatalog). Las
     * columnas de `setting_id`/`setting_key` quedan solo para filas
     * `entity_type = 'setting'`; se retrocompletan en filas existentes.
     */
    public function up(): void
    {
        Schema::table('config_audit_logs', function (Blueprint $table) {
            $table->string('entity_type', 40)->default('setting')->after('id');
            $table->string('action', 180)->nullable()->after('entity_type');
            $table->string('setting_key', 80)->nullable()->change();
        });

        DB::table('config_audit_logs')->whereNull('action')->update(['action' => DB::raw('setting_key')]);
    }

    public function down(): void
    {
        Schema::table('config_audit_logs', function (Blueprint $table) {
            $table->dropColumn(['entity_type', 'action']);
            $table->string('setting_key', 80)->nullable(false)->change();
        });
    }
};
