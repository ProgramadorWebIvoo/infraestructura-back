<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renombra los estados de proyecto *_CIERRE a *_AUDITORIA (projects.status y
 * cualquier texto persistido que los cite). Idempotente y reversible.
 * En MySQL el ENUM se amplía primero con ambos juegos de valores, se migran
 * las filas y luego se reduce al juego final.
 */
return new class extends Migration
{
    private const MAP = [
        'REVISADO_CIERRE' => 'REVISADO_AUDITORIA',
        'RECHAZADO_CIERRE' => 'RECHAZADO_AUDITORIA',
        'EN_REEVALUACION_CIERRE' => 'EN_REEVALUACION_AUDITORIA',
    ];

    private const COMMON = [
        'CREADO', 'CONFIRMADO_PROCURA', 'COMPARATIVA_ENVIADA', 'CONTRATADO',
        'EN_EJECUCION', 'VERIFICANDO_FINALIZACION', 'LISTO_PAGO_FINAL', 'COMPLETADO_PAGADO',
    ];

    public function up(): void
    {
        $this->migrate(self::MAP);
    }

    public function down(): void
    {
        $this->migrate(array_flip(self::MAP));
    }

    private function migrate(array $map): void
    {
        $enum = fn (array $values) => implode(',', array_map(fn ($v) => "'{$v}'", $values));
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        $final = array_values(array_merge(['CREADO'], array_values($map), array_slice(self::COMMON, 1)));

        if ($mysql) {
            $both = array_values(array_unique(array_merge(self::COMMON, array_keys($map), array_values($map))));
            DB::statement("ALTER TABLE projects MODIFY status ENUM({$enum($both)}) NOT NULL DEFAULT 'CREADO'");
        }

        foreach ($map as $from => $to) {
            DB::table('projects')->where('status', $from)->update(['status' => $to]);

            foreach ([['audit_logs', 'details'], ['audit_logs', 'action'], ['app_notifications', 'action'], ['app_notifications', 'details'], ['app_settings', 'value']] as [$table, $col]) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $col)) {
                    DB::table($table)->where($col, 'like', "%{$from}%")->update([$col => DB::raw("REPLACE({$col}, '{$from}', '{$to}')")]);
                }
            }
        }

        if ($mysql) {
            DB::statement("ALTER TABLE projects MODIFY status ENUM({$enum($final)}) NOT NULL DEFAULT 'CREADO'");
        }
    }
};
