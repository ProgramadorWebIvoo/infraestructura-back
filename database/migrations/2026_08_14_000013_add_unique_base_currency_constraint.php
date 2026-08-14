<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Exactamente una moneda base" hasta ahora solo se garantizaba en
     * CurrencyController::setBase() (transacción que apaga la anterior y
     * prende la nueva) — sin ninguna restricción de esquema. Cualquier otro
     * camino de escritura (un seeder futuro, una corrección manual por SQL,
     * una transacción que falla a mitad de camino) podía dejar 0 o 2 filas
     * con is_base=true sin que nada lo impidiera.
     *
     * MySQL/MariaDB no soporta índices únicos parciales (a diferencia de
     * Postgres) — la técnica estándar es una columna generada que colapsa
     * is_base=false a NULL (los NULL no colisionan en un índice único), e
     * indexar esa columna como única. Con esto, un segundo UPDATE/INSERT
     * que intente poner is_base=true mientras ya existe otra fila con
     * is_base=true falla a nivel de BD, no solo de aplicación.
     */
    public function up(): void
    {
        // SQLite (tests) soporta índices únicos parciales nativamente — no
        // necesita la columna generada que sí requiere MySQL/MariaDB.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX uq_currencies_single_base ON currencies (is_base) WHERE is_base = 1');
            return;
        }

        DB::statement(
            "ALTER TABLE `currencies` ADD COLUMN `is_base_flag` TINYINT(1) " .
            "GENERATED ALWAYS AS (IF(`is_base` = 1, 1, NULL)) VIRTUAL"
        );
        Schema::table('currencies', function ($table) {
            $table->unique('is_base_flag', 'uq_currencies_single_base');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS uq_currencies_single_base');
            return;
        }

        Schema::table('currencies', function ($table) {
            $table->dropUnique('uq_currencies_single_base');
        });
        DB::statement('ALTER TABLE `currencies` DROP COLUMN `is_base_flag`');
    }
};
