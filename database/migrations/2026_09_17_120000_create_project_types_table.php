<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de tipos de proyecto — reemplaza el `Rule::in(['INFRAESTRUCTURA',
 * 'MANTENIMIENTO'])` hardcodeado en StoreProjectRequest. Mismo patrón que
 * material_catalog: `key` es el valor persistido en projects.type (string
 * libre, sin FK, igual que projects.status), `label` es solo cosmético.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('project_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('label', 120);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('project_types')->insert([
            ['key' => 'INFRAESTRUCTURA', 'label' => 'Infraestructura', 'is_active' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'MANTENIMIENTO', 'label' => 'Mantenimiento', 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('project_types');
    }
};
