<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tabs del cierre posterior a la ejecución: "Ejecución y cierre" (bandeja del
 * residente en /infraestructura) y "Solicitud de finiquito" (Procura). Los
 * tab_key deben coincidir con los TabDefinition.key de cada panel.
 */
return new class extends Migration
{
    private const TABS = [
        ['/infraestructura', 'cierre', 'Ejecución y cierre'],
        ['/procura', 'finiquito', 'Solicitud de finiquito'],
    ];

    public function up(): void
    {
        $now = now();

        DB::table('tab_definitions')->upsert(
            array_map(fn ($t) => ['view_key' => $t[0], 'tab_key' => $t[1], 'label' => $t[2], 'default_active' => true, 'created_at' => $now, 'updated_at' => $now], self::TABS),
            ['view_key', 'tab_key'],
            ['label', 'default_active', 'updated_at']
        );
    }

    public function down(): void
    {
        foreach (self::TABS as [$view, $tab]) {
            DB::table('tab_definitions')->where('view_key', $view)->where('tab_key', $tab)->delete();
        }
    }
};
