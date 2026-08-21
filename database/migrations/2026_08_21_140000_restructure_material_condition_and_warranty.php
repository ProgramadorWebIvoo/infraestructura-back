<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement("ALTER TABLE project_materials MODIFY `condition` ENUM('NUEVO','USADO','AMBAS') NULL");
        } else {
            // SQLite (tests): ver 2026_08_20_180842_add_foto_to_project_documents_document_type_enum.php
            // — doctrine/dbal no modela ENUM nativamente, así que change() lo
            // trata como string y elimina el CHECK constraint. Aceptable: la
            // validación real vive en StoreProjectRequest.
            Schema::table('project_materials', function (Blueprint $table) {
                $table->string('condition', 20)->nullable()->change();
            });
        }

        Schema::table('project_materials', function (Blueprint $table) {
            $table->unsignedInteger('warranty_value')->nullable()->after('warranty');
            $table->enum('warranty_unit', ['DIAS', 'MESES', 'ANOS'])->nullable()->after('warranty_value');
        });

        // No hay datos existentes en producción que parseen "warranty" (string libre,
        // formato no estructurado) a warranty_value/warranty_unit de forma confiable —
        // se descarta el valor legado en vez de intentar un parseo heurístico.
        Schema::table('project_materials', function (Blueprint $table) {
            $table->dropColumn('warranty');
        });
    }

    public function down()
    {
        Schema::table('project_materials', function (Blueprint $table) {
            $table->string('warranty', 120)->nullable()->after('condition');
        });

        Schema::table('project_materials', function (Blueprint $table) {
            $table->dropColumn(['warranty_value', 'warranty_unit']);
        });

        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement("UPDATE project_materials SET `condition` = NULL WHERE `condition` = 'AMBAS'");
            DB::statement("ALTER TABLE project_materials MODIFY `condition` ENUM('NUEVO','USADO') NULL");
        } else {
            DB::table('project_materials')->where('condition', 'AMBAS')->update(['condition' => null]);
            Schema::table('project_materials', function (Blueprint $table) {
                $table->enum('condition', ['NUEVO', 'USADO'])->nullable()->change();
            });
        }
    }
};
