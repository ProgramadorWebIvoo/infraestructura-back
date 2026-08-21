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
            DB::statement("ALTER TABLE projects MODIFY status ENUM(
                'CREADO',
                'REVISADO_CIERRE',
                'RECHAZADO_CIERRE',
                'CONFIRMADO_PROCURA',
                'COMPARATIVA_ENVIADA',
                'CONTRATADO',
                'EN_EJECUCION',
                'VERIFICANDO_FINALIZACION',
                'LISTO_PAGO_FINAL',
                'COMPLETADO_PAGADO'
            ) NOT NULL DEFAULT 'CREADO'");
        } else {
            // SQLite (tests): ver 2026_08_20_180842_add_foto_to_project_documents_document_type_enum.php
            // — doctrine/dbal no modela ENUM nativamente, así que change() lo
            // trata como string y elimina el CHECK constraint. Aceptable: la
            // validación real vive en los FormRequests.
            Schema::table('projects', function (Blueprint $table) {
                $table->string('status', 30)->default('CREADO')->change();
            });
        }
    }

    public function down()
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement("UPDATE projects SET status = 'CREADO' WHERE status = 'RECHAZADO_CIERRE'");
            DB::statement("ALTER TABLE projects MODIFY status ENUM(
                'CREADO',
                'REVISADO_CIERRE',
                'CONFIRMADO_PROCURA',
                'COMPARATIVA_ENVIADA',
                'CONTRATADO',
                'EN_EJECUCION',
                'VERIFICANDO_FINALIZACION',
                'LISTO_PAGO_FINAL',
                'COMPLETADO_PAGADO'
            ) NOT NULL DEFAULT 'CREADO'");
        } else {
            DB::table('projects')->where('status', 'RECHAZADO_CIERRE')->update(['status' => 'CREADO']);
        }
    }
};
