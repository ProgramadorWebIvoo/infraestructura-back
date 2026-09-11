<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migra config/permissions.php (matriz rol→rutas hardcodeada) a
 * view_definitions + role_view_access, y siembra tab_definitions con las
 * tabs actualmente hardcodeadas en cada panel (ver Tabs.tsx de cada vista).
 * Ambos catálogos quedan editables desde el panel de Usuarios sin deploy.
 *
 * Idempotente vía upsert sobre las UNIQUE keys — puede re-ejecutarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $viewLabels = [
            '/presidencia' => 'Presidencia',
            '/marketing' => 'Marketing',
            '/infraestructura' => 'Infraestructura',
            '/cierre-obra' => 'Cierre de Obra',
            '/procura' => 'Procura',
            '/analistas' => 'Analistas',
            '/finanzas' => 'Finanzas',
            '/catalogos' => 'Catálogos',
            '/usuarios' => 'Usuarios',
            '/config-proveedores' => 'Configuración de Proveedores',
            '/config-materiales' => 'Configuración de Materiales',
            '/config-ia' => 'Configuración de IA',
            '/config-app' => 'Configuración de Aplicación',
        ];

        $viewRows = [];
        foreach ($viewLabels as $key => $label) {
            $viewRows[] = ['key' => $key, 'label' => $label, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('view_definitions')->upsert($viewRows, ['key'], ['label', 'updated_at']);

        $viewIds = DB::table('view_definitions')->pluck('id', 'key');

        $rolePermissions = require base_path('config/permissions.php');
        $roleViewRows = [];
        foreach ($rolePermissions as $role => $paths) {
            foreach ($paths as $path) {
                if (! isset($viewIds[$path])) {
                    continue;
                }
                $roleViewRows[] = [
                    'role' => $role,
                    'view_definition_id' => $viewIds[$path],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($roleViewRows, 200) as $chunk) {
            DB::table('role_view_access')->upsert($chunk, ['role', 'view_definition_id'], ['updated_at']);
        }

        // ── Catálogo de tabs por vista — copia exacta de los TabDefinition[]
        // hardcodeados hoy en cada panel (ver *Panel/index.tsx). Todas nacen
        // default_active=true: "por default vienen todas activas".
        $tabsByView = [
            '/infraestructura' => [
                ['crear', 'Crear'],
                ['expedientes', 'Expedientes'],
                ['rechazadas', 'Rechazadas'],
            ],
            '/cierre-obra' => [
                ['revision', 'Revisión de Cálculos y Planos'],
                ['auditoria', 'Auditoría de Fin de Obra'],
                ['documentos', 'Historial de Expedientes'],
            ],
            '/procura' => [
                ['autorizacion', 'Autorización de Inversión'],
                ['comparativa', 'Evaluación Comparativa'],
            ],
            '/finanzas' => [
                ['stats', 'Estadisticas'],
                ['book', 'Diario de Egresos'],
                ['advances', 'Anticipos'],
                ['settlements', 'Finiquitos'],
            ],
            '/marketing' => [
                ['proyectos', 'Proyectos'],
                ['crear', 'Crear'],
                ['historial', 'Historial de Proyectos'],
            ],
        ];

        $tabRows = [];
        foreach ($tabsByView as $viewKey => $tabs) {
            foreach ($tabs as [$tabKey, $label]) {
                $tabRows[] = [
                    'view_key' => $viewKey,
                    'tab_key' => $tabKey,
                    'label' => $label,
                    'default_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('tab_definitions')->upsert($tabRows, ['view_key', 'tab_key'], ['label', 'default_active', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('tab_definitions')->truncate();
        DB::table('role_view_access')->truncate();
        DB::table('view_definitions')->truncate();
    }
};
