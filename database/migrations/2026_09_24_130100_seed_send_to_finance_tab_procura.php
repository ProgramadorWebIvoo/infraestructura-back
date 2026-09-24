<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nueva tab "Envío a Finanzas" en /procura: Procura envía a Finanzas las
 * adjudicaciones aprobadas por Presidencia. Mismo patrón que
 * 2026_09_15_000002_seed_reevaluacion_tab_cierre_obra.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('tab_definitions')->upsert([
            [
                'view_key' => '/procura',
                'tab_key' => 'finanzas',
                'label' => 'Envío a Finanzas',
                'default_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['view_key', 'tab_key'], ['label', 'default_active', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('tab_definitions')->where('view_key', '/procura')->where('tab_key', 'finanzas')->delete();
    }
};
