<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'COMPROBANTE_ANTICIPO'/'COMPROBANTE_FINIQUITO': comprobante bancario
     * (imagen o PDF) que Finanzas adjunta como evidencia al liberar un
     * anticipo o liquidar un finiquito — distinto de CALC/PLANO/FOTO/
     * CORRECCION, que son documentación técnica de Cierre de Obra/
     * Infraestructura. Mismo patrón que
     * 2026_08_21_170000_add_correccion_to_project_documents_document_type_enum.php.
     */
    public function up()
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement("ALTER TABLE project_documents MODIFY document_type ENUM('CALC','PLANO','FOTO','CORRECCION','COMPROBANTE_ANTICIPO','COMPROBANTE_FINIQUITO') NOT NULL");

            return;
        }

        Schema::table('project_documents', function (Blueprint $table) {
            $table->string('document_type', 25)->change();
        });
    }

    public function down()
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement("UPDATE project_documents SET document_type = 'FOTO' WHERE document_type IN ('COMPROBANTE_ANTICIPO','COMPROBANTE_FINIQUITO')");
            DB::statement("ALTER TABLE project_documents MODIFY document_type ENUM('CALC','PLANO','FOTO','CORRECCION') NOT NULL");

            return;
        }

        DB::table('project_documents')->whereIn('document_type', ['COMPROBANTE_ANTICIPO', 'COMPROBANTE_FINIQUITO'])->update(['document_type' => 'FOTO']);
        Schema::table('project_documents', function (Blueprint $table) {
            $table->enum('document_type', ['CALC', 'PLANO', 'FOTO', 'CORRECCION'])->change();
        });
    }
};
