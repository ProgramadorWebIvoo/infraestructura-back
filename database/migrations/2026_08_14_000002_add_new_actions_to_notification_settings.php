<?php

use App\Support\NotificationCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega al setting `acciones_con_notificacion_app` las acciones nuevas
     * introducidas en la Fase A del plan de notificaciones configurables por
     * rol (usuarios, proveedores/materiales del panel admin, config de IA,
     * evaluación IA con nombre de acción fijo, envío de invitación a
     * proveedor) — sin esto, el interruptor maestro las excluiría por
     * omisión aunque ya estén siendo auditadas/notificadas por el código.
     * No se sobreescribe el valor completo (evita pisar acciones que el
     * SUPERADMIN ya haya desactivado manualmente) — solo se agregan las
     * claves nuevas que falten.
     */
    public function up(): void
    {
        $setting = DB::table('app_settings')->where('key', 'acciones_con_notificacion_app')->first();

        if ($setting === null) {
            return;
        }

        $current = json_decode($setting->value, true) ?? [];
        $missing = array_diff(NotificationCatalog::keys(), $current);

        if (empty($missing)) {
            return;
        }

        DB::table('app_settings')
            ->where('key', 'acciones_con_notificacion_app')
            ->update([
                'value' => json_encode([...$current, ...array_values($missing)]),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // No reversible de forma segura sin conocer qué acciones tenía el
        // SUPERADMIN desactivadas manualmente antes de este deploy — el
        // rollback de este setting, si hace falta, se hace manualmente
        // desde CONFIG APP.
    }
};
