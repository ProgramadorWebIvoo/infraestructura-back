<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SUPERADMIN debe ver todas las vistas del SPA, pero role_view_access quedó
 * sin dos:
 *  - /auditoria: sin filas para ningún rol (config/permissions.php la da a
 *    SUPERADMIN, ADMIN y AUDITORIA), se restaura tal cual la config.
 *  - /residente: 2026_09_25_000006 solo la sembró para RESIDENTE.
 *
 * Idempotente (upsert sobre la UNIQUE role+view). Solo agrega accesos, así que
 * down() no borra nada: no se puede distinguir lo sembrado aquí de lo previo.
 */
return new class extends Migration
{
    private const GRANTS = [
        '/auditoria' => ['SUPERADMIN', 'ADMIN', 'AUDITORIA'],
        '/residente' => ['SUPERADMIN'],
    ];

    public function up(): void
    {
        $now = now();
        $viewIds = DB::table('view_definitions')->whereIn('key', array_keys(self::GRANTS))->pluck('id', 'key');

        $rows = [];
        foreach (self::GRANTS as $view => $roles) {
            if (! isset($viewIds[$view])) {
                continue;
            }
            foreach ($roles as $role) {
                $rows[] = ['role' => $role, 'view_definition_id' => $viewIds[$view], 'created_at' => $now, 'updated_at' => $now];
            }
        }

        if ($rows !== []) {
            DB::table('role_view_access')->upsert($rows, ['role', 'view_definition_id'], ['updated_at']);
        }
    }

    public function down(): void
    {
    }
};
