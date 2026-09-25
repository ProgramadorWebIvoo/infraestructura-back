<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F3: pestaña "Modificaciones" de /infraestructura. El tab_key debe coincidir
 * con el TabDefinition.key del panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('tab_definitions')->upsert(
            [['view_key' => '/infraestructura', 'tab_key' => 'modificaciones', 'label' => 'Modificaciones', 'default_active' => true, 'created_at' => $now, 'updated_at' => $now]],
            ['view_key', 'tab_key'],
            ['label', 'default_active', 'updated_at']
        );
    }

    public function down(): void
    {
        DB::table('tab_definitions')->where('view_key', '/infraestructura')->where('tab_key', 'modificaciones')->delete();
    }
};
