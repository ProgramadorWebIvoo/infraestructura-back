<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Aviso opcional editable desde CONFIG APP que se muestra en el banner
     * del Home ("/") de todos los roles — para comunicados generales
     * (mantenimiento programado, aviso operativo) sin depender de un correo
     * o notificación puntual. Vacío por defecto: el Home no muestra nada
     * hasta que un SUPERADMIN/ADMIN escribe un texto.
     */
    public function up(): void
    {
        DB::table('app_settings')->insert([
            'group' => 'app',
            'key' => 'home_anuncio',
            'value' => null,
            'type' => 'string',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('app_settings')->where('key', 'home_anuncio')->delete();
    }
};
