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
            DB::statement("ALTER TABLE project_documents MODIFY document_type ENUM('CALC','PLANO','FOTO') NOT NULL");

            return;
        }

        // SQLite (tests): el enum() de Laravel se traduce a un CHECK
        // constraint real (a diferencia de MySQL, donde el fix histórico de
        // 2026_07_23 asumía que solo MySQL necesitaba ALTER). doctrine/dbal
        // no modela ENUM nativamente, así que change() lo trata como string
        // y elimina el CHECK — comportamiento aceptable aquí, ya que la
        // validación real de valores permitidos vive en
        // StoreProjectDocumentRequest, no en la constraint de BD.
        Schema::table('project_documents', function (Blueprint $table) {
            $table->string('document_type', 20)->change();
        });
    }

    public function down()
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement("UPDATE project_documents SET document_type = 'PLANO' WHERE document_type = 'FOTO'");
            DB::statement("ALTER TABLE project_documents MODIFY document_type ENUM('CALC','PLANO') NOT NULL");

            return;
        }

        DB::table('project_documents')->where('document_type', 'FOTO')->update(['document_type' => 'PLANO']);
        Schema::table('project_documents', function (Blueprint $table) {
            $table->enum('document_type', ['CALC', 'PLANO'])->change();
        });
    }
};
