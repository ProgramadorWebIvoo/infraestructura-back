<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nueva tab "Reevaluaciones de Procura" en /cierre-obra (ver
 * CierreObraPanel/index.tsx + ReevaluationSection.tsx) — flujo de
 * reevaluación de Procura hacia Cierre de Obra. Mismo patrón que
 * 2026_09_11_000006_seed_view_and_tab_access.php: upsert idempotente,
 * default_active=true ("por default vienen todas las tabs activas").
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('tab_definitions')->upsert([
            [
                'view_key' => '/cierre-obra',
                'tab_key' => 'reevaluacion',
                'label' => 'Reevaluaciones de Procura',
                'default_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['view_key', 'tab_key'], ['label', 'default_active', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('tab_definitions')->where('view_key', '/cierre-obra')->where('tab_key', 'reevaluacion')->delete();
    }
};
