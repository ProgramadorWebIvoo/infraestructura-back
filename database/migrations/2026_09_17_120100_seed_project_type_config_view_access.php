<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registra la vista /config-project-types (nuevo panel de tipos de
 * proyecto en ConfigAppPanel) en view_definitions + role_view_access,
 * mismo criterio que 2026_09_11_000006_seed_view_and_tab_access — SUPERADMIN
 * y ADMIN, igual acceso que /config-materiales.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('view_definitions')->upsert(
            [['key' => '/config-project-types', 'label' => 'Configuración de Tipos de Proyecto', 'created_at' => $now, 'updated_at' => $now]],
            ['key'],
            ['label', 'updated_at'],
        );

        $viewId = DB::table('view_definitions')->where('key', '/config-project-types')->value('id');

        DB::table('role_view_access')->upsert(
            [
                ['role' => 'SUPERADMIN', 'view_definition_id' => $viewId, 'created_at' => $now, 'updated_at' => $now],
                ['role' => 'ADMIN', 'view_definition_id' => $viewId, 'created_at' => $now, 'updated_at' => $now],
            ],
            ['role', 'view_definition_id'],
            ['updated_at'],
        );
    }

    public function down(): void
    {
        $viewId = DB::table('view_definitions')->where('key', '/config-project-types')->value('id');
        if ($viewId) {
            DB::table('role_view_access')->where('view_definition_id', $viewId)->delete();
            DB::table('view_definitions')->where('id', $viewId)->delete();
        }
    }
};
