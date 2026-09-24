<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía projects.status con los estados del circuito de aprobación de
 * Presidencia (que faltaban en el ENUM de MySQL) y del cierre posterior a la
 * ejecución (INFORME_ENVIADO, PENDIENTE_SOLICITUD_FINIQUITO), y agrega el
 * residente/coordinador asignable por obra.
 */
return new class extends Migration
{
    private const BASE = [
        'CREADO', 'REVISADO_AUDITORIA', 'RECHAZADO_AUDITORIA', 'EN_REEVALUACION_AUDITORIA',
        'CONFIRMADO_PROCURA', 'COMPARATIVA_ENVIADA',
    ];

    private const ADDED_APPROVAL = ['PENDIENTE_PRESIDENCIA', 'APROBADO_PRESIDENCIA'];

    private const TAIL = ['CONTRATADO', 'EN_EJECUCION'];

    private const ADDED_CLOSURE = ['INFORME_ENVIADO'];

    private const CLOSURE_MID = ['VERIFICANDO_FINALIZACION'];

    private const ADDED_FINIQUITO = ['PENDIENTE_SOLICITUD_FINIQUITO'];

    private const END = ['LISTO_PAGO_FINAL', 'COMPLETADO_PAGADO'];

    public function up(): void
    {
        $this->setEnum(array_merge(
            self::BASE, self::ADDED_APPROVAL, self::TAIL, self::ADDED_CLOSURE,
            self::CLOSURE_MID, self::ADDED_FINIQUITO, self::END
        ));

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('resident_user_id')->nullable()->after('selected_proposal_id');
            $table->foreign('resident_user_id', 'fk_projects_resident_user')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign('fk_projects_resident_user');
            $table->dropColumn('resident_user_id');
        });

        $removed = array_merge(self::ADDED_APPROVAL, self::ADDED_CLOSURE, self::ADDED_FINIQUITO);
        DB::table('projects')->where('status', 'INFORME_ENVIADO')->update(['status' => 'EN_EJECUCION']);
        DB::table('projects')->where('status', 'PENDIENTE_SOLICITUD_FINIQUITO')->update(['status' => 'VERIFICANDO_FINALIZACION']);
        DB::table('projects')->whereIn('status', self::ADDED_APPROVAL)->update(['status' => 'COMPARATIVA_ENVIADA']);

        $this->setEnum(array_values(array_diff(
            array_merge(self::BASE, self::ADDED_APPROVAL, self::TAIL, self::ADDED_CLOSURE, self::CLOSURE_MID, self::ADDED_FINIQUITO, self::END),
            $removed
        )));
    }

    private function setEnum(array $values): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $list = implode(',', array_map(fn ($v) => "'{$v}'", $values));
        DB::statement("ALTER TABLE projects MODIFY status ENUM({$list}) NOT NULL DEFAULT 'CREADO'");
    }
};
