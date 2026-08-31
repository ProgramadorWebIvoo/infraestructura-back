<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RIF (Registro de Información Fiscal venezolano) del proveedor — dato
 * fiscal obligatorio, nunca opcional. Se agrega en 3 pasos para no romper
 * proveedores ya existentes: 1) columna nullable, 2) backfill con un
 * placeholder identificable que un admin debe corregir manualmente,
 * 3) columna NOT NULL — a partir de aquí el backend ya no permite crear
 * ni editar un proveedor sin RIF real (ver StoreContractorRequest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->string('rif', 15)->nullable()->after('name');
        });

        // update() por fila en vez de CONCAT() en SQL crudo: CONCAT no existe
        // en SQLite (usado en tests), y este backfill corre una sola vez por
        // entorno, no es una ruta caliente.
        // Placeholder corto ("PEND-" + code) para caber en varchar(15) junto
        // a un código de proveedor real (ej. "PEND-CON-30123" = 14 chars) —
        // un RIF real nunca empieza con "PEND-", así que sigue siendo
        // trivialmente identificable para que el admin lo corrija.
        DB::table('contractors')->whereNull('rif')->get(['code'])->each(function ($row) {
            DB::table('contractors')->where('code', $row->code)->update([
                'rif' => substr('PEND-' . $row->code, 0, 15),
            ]);
        });

        Schema::table('contractors', function (Blueprint $table) {
            $table->string('rif', 15)->nullable(false)->change();
            $table->unique('rif');
        });
    }

    public function down(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->dropUnique(['rif']);
            $table->dropColumn('rif');
        });
    }
};
