<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Registra 'Carga de fotografias del sitio de obra' en notification_rules
     * — esta acción faltaba por completo en NotificationCatalog (los otros 3
     * tipos de documento: CALC, PLANO, CORRECCION sí estaban catalogados y
     * configurados, FOTO no). Sin entrada en el catálogo, la acción caía al
     * fallback de NotificationRuleResolver (solo SUPERADMIN/ADMIN, canal app,
     * sin mail) en vez de respetar la configuración real de CONFIG APP —
     * reportado por QA: subir fotos notificaba a roles distintos de los
     * configurados para el resto de cargas de documentos. Mismo patrón que
     * 'Carga de hojas de calculo/cubicaciones' y 'Carga de planos de
     * ingenieria' (2026_08_14_000005_seed_notification_rules.php).
     */
    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach (['CIERRE_DE_OBRA', 'SUPERADMIN', 'ADMIN'] as $role) {
            $rows[] = [
                'action' => 'Carga de fotografias del sitio de obra',
                'role' => $role,
                'channel' => 'app',
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('notification_rules')->upsert(
            $rows,
            ['action', 'role', 'channel'],
            ['enabled', 'updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('notification_rules')
            ->where('action', 'Carga de fotografias del sitio de obra')
            ->delete();
    }
};
