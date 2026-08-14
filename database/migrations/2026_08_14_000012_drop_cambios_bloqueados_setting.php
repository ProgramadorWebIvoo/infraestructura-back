<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `cambios_bloqueados` es un flag muerto desde su creación: ningún
     * middleware ni controller lo lee, solo existía su entrada de catálogo.
     * Un control que aparenta hacer algo sin hacer nada es peor que no
     * tenerlo — se elimina en vez de implementarle semántica real (modo
     * mantenimiento) que nadie pidió.
     */
    public function up(): void
    {
        DB::table('app_settings')->where('key', 'cambios_bloqueados')->delete();
    }

    public function down(): void
    {
        DB::table('app_settings')->insert([
            'group' => 'app',
            'key' => 'cambios_bloqueados',
            'value' => 'false',
            'type' => 'boolean',
            'min_value' => null,
            'max_value' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
