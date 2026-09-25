<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F2-R R6: el creador de la solicitud ya no participa en el cierre; su
 * seguimiento vive en "Expedientes". Se retira la pestaña "Ejecución y cierre".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tab_definitions')->where('view_key', '/infraestructura')->where('tab_key', 'cierre')->delete();
    }

    public function down(): void
    {
        $now = now();

        DB::table('tab_definitions')->upsert(
            [['view_key' => '/infraestructura', 'tab_key' => 'cierre', 'label' => 'Ejecución y cierre', 'default_active' => true, 'created_at' => $now, 'updated_at' => $now]],
            ['view_key', 'tab_key'],
            ['label', 'default_active', 'updated_at']
        );
    }
};
