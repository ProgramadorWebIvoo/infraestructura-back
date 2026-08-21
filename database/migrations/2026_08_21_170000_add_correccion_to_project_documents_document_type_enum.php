<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'CORRECCION': documentos que Cierre de Obra adjunta opcionalmente al
     * rechazar una petición (planos/hojas corregidas indicando qué arreglar)
     * — distinto de PLANO/CALC, que son los adjuntos originales de la
     * petición. Mismo patrón que 2026_08_20_180842_add_foto_to_project_documents_document_type_enum.php.
     */
    public function up()
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement("ALTER TABLE project_documents MODIFY document_type ENUM('CALC','PLANO','FOTO','CORRECCION') NOT NULL");

            return;
        }

        Schema::table('project_documents', function (Blueprint $table) {
            $table->string('document_type', 20)->change();
        });
    }

    public function down()
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement("UPDATE project_documents SET document_type = 'FOTO' WHERE document_type = 'CORRECCION'");
            DB::statement("ALTER TABLE project_documents MODIFY document_type ENUM('CALC','PLANO','FOTO') NOT NULL");

            return;
        }

        DB::table('project_documents')->where('document_type', 'CORRECCION')->update(['document_type' => 'FOTO']);
        Schema::table('project_documents', function (Blueprint $table) {
            $table->enum('document_type', ['CALC', 'PLANO', 'FOTO'])->change();
        });
    }
};
