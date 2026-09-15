<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Siembra la vista '/config-keys' (tab "Configuración de Keys" dentro de
 * CONFIG APP) en view_definitions + role_view_access. A diferencia de las
 * demás tabs de EXTRA_TABS, solo SUPERADMIN la ve por default (no ADMIN):
 * expone credenciales de infraestructura (SMTP, Pusher), no configuración
 * de negocio. Sigue el mismo patrón que
 * 2026_09_11_000006_seed_view_and_tab_access.php pero como migración nueva
 * porque aquella ya corrió en producción (upsert la haría re-triggerable,
 * pero se evita tocar migraciones ya aplicadas).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('view_definitions')->upsert(
            [['key' => '/config-keys', 'label' => 'Configuración de Keys', 'created_at' => $now, 'updated_at' => $now]],
            ['key'],
            ['label', 'updated_at'],
        );

        $viewId = DB::table('view_definitions')->where('key', '/config-keys')->value('id');

        DB::table('role_view_access')->upsert(
            [['role' => 'SUPERADMIN', 'view_definition_id' => $viewId, 'created_at' => $now, 'updated_at' => $now]],
            ['role', 'view_definition_id'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        $viewId = DB::table('view_definitions')->where('key', '/config-keys')->value('id');
        if ($viewId) {
            DB::table('role_view_access')->where('view_definition_id', $viewId)->delete();
            DB::table('user_view_access')->where('view_definition_id', $viewId)->delete();
            DB::table('view_definitions')->where('id', $viewId)->delete();
        }
    }
};
