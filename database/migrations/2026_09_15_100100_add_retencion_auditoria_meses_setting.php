<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Setting de retención para el nuevo comando `audit:prune` (Fase 3 del plan
 * de refuerzo de auditorías) — mismo patrón que
 * 2026_08_14_000011_seed_app_group_settings.php. Rango en meses (no días,
 * a diferencia de retencion_notificaciones_dias): la auditoría es evidencia
 * de largo plazo, no una bandeja operativa — 24 meses por defecto, acotado
 * a 6-60 para evitar tanto un purgado agresivo por error como una tabla que
 * crezca indefinidamente por omisión de configurarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('app_settings')->insert([
            'group' => 'app',
            'key' => 'retencion_auditoria_meses',
            'value' => '24',
            'type' => 'integer',
            'min_value' => 6,
            'max_value' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('app_settings')->where('key', 'retencion_auditoria_meses')->delete();
    }
};
