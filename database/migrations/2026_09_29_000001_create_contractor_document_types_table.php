<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('contractor_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('label', 150);
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        $now = now();
        $types = [
            ['rif', 'RIF de la empresa'],
            ['registro_comercio', 'Registro de comercio'],
            ['acta_accionistas', 'Acta / junta de accionistas'],
            ['cedula_representante', 'Cédula del representante legal'],
            ['rif_representante', 'RIF del representante legal'],
        ];

        foreach ($types as $index => [$key, $label]) {
            DB::table('contractor_document_types')->insert([
                'key' => $key,
                'label' => $label,
                'is_required' => true,
                'is_active' => true,
                'sort_order' => ($index + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down()
    {
        Schema::dropIfExists('contractor_document_types');
    }
};
