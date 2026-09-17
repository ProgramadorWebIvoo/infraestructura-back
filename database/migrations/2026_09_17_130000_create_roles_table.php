<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de roles — reemplaza App\Support\Roles::VALID (const array
 * hardcodeado). `key` es el valor persistido en users.role (string libre,
 * sin FK, mismo criterio que projects.status) — agregar/quitar un rol ya
 * no requiere editar código ni redeploy.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('label', 120);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        $roles = [
            ['key' => 'SUPERADMIN', 'label' => 'Super Administrador'],
            ['key' => 'ADMIN', 'label' => 'Administrador'],
            ['key' => 'PRESIDENCIA', 'label' => 'Presidencia'],
            ['key' => 'INFRAESTRUCTURA', 'label' => 'Infraestructura / Mant.'],
            ['key' => 'CIERRE_DE_OBRA', 'label' => 'Cierre de Obra'],
            ['key' => 'PROCURA', 'label' => 'Procura'],
            ['key' => 'ANALISTA', 'label' => 'Analistas'],
            ['key' => 'FINANZAS', 'label' => 'Finanzas'],
            ['key' => 'CATALOGOS', 'label' => 'Catálogos'],
            ['key' => 'MARKETING', 'label' => 'Marketing'],
        ];

        foreach ($roles as $i => &$row) {
            $row['is_active'] = true;
            $row['sort_order'] = $i;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        DB::table('roles')->insert($roles);
    }

    public function down()
    {
        Schema::dropIfExists('roles');
    }
};
