<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'EN_REEVALUACION_CIERRE': Procura devuelve un expediente a Cierre de
     * Obra con un motivo antes de autorizar inversión (ver
     * ProjectController::sendToReevaluation) — mismo patrón que
     * 2026_08_21_150000_add_rechazado_cierre_status_to_projects.php.
     */
    public function up()
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement("ALTER TABLE projects MODIFY status ENUM(
                'CREADO',
                'REVISADO_CIERRE',
                'RECHAZADO_CIERRE',
                'EN_REEVALUACION_CIERRE',
                'CONFIRMADO_PROCURA',
                'COMPARATIVA_ENVIADA',
                'CONTRATADO',
                'EN_EJECUCION',
                'VERIFICANDO_FINALIZACION',
                'LISTO_PAGO_FINAL',
                'COMPLETADO_PAGADO'
            ) NOT NULL DEFAULT 'CREADO'");

            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->string('status', 30)->default('CREADO')->change();
        });
    }

    public function down()
    {
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement("UPDATE projects SET status = 'REVISADO_CIERRE' WHERE status = 'EN_REEVALUACION_CIERRE'");
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

            return;
        }

        DB::table('projects')->where('status', 'EN_REEVALUACION_CIERRE')->update(['status' => 'REVISADO_CIERRE']);
    }
};
