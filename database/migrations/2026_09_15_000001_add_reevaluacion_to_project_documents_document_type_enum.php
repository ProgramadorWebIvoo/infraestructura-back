<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'REEVALUACION': evidencia que Procura adjunta al enviar un expediente
     * de vuelta a Cierre de Obra (ver ProjectController::sendToReevaluation)
     * — mismo patrón que
     * 2026_08_21_170000_add_correccion_to_project_documents_document_type_enum.php.
     */
    public function up()
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement("ALTER TABLE project_documents MODIFY document_type ENUM('CALC','PLANO','FOTO','CORRECCION','REEVALUACION','COMPROBANTE_ANTICIPO','COMPROBANTE_FINIQUITO') NOT NULL");

            return;
        }

        Schema::table('project_documents', function (Blueprint $table) {
            $table->string('document_type', 25)->change();
        });
    }

    public function down()
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement("UPDATE project_documents SET document_type = 'FOTO' WHERE document_type = 'REEVALUACION'");
            DB::statement("ALTER TABLE project_documents MODIFY document_type ENUM('CALC','PLANO','FOTO','CORRECCION','COMPROBANTE_ANTICIPO','COMPROBANTE_FINIQUITO') NOT NULL");

            return;
        }

        DB::table('project_documents')->where('document_type', 'REEVALUACION')->update(['document_type' => 'FOTO']);
    }
};
