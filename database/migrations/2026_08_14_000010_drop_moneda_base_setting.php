<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `moneda_base` pasa de string libre en app_settings a la fila
     * is_base=true de la nueva tabla `currencies` (ver
     * 2026_08_14_000009_create_currencies_table). Mismo patrón que
     * 2026_08_14_000006 (remoción de flags obsoletos de app_settings).
     *
     * ADVERTENCIA de orden de rollback: `down()` resucita la fila
     * `moneda_base` en app_settings pero NO restaura el estado de
     * `currencies` (eso lo maneja el down() de 2026_08_14_000009 por
     * separado). Si se hace rollback de ESTA migración de forma aislada
     * (ej. `migrate:rollback --step=1` justo después de este deploy, sin
     * también revertir 2026_08_14_000009), el resultado es un estado
     * inconsistente: la fila `moneda_base` resucitada (sin entrada en
     * AppSettingCatalog, por lo tanto con label/description null) coexiste
     * con la tabla `currencies` todavía viva y con su propia fila
     * is_base=true — dos "fuentes de verdad" de la moneda base sin
     * ningún código que las reconcilie. Revertir siempre ambas migraciones
     * juntas, en orden inverso al que se aplicaron.
     */
    public function up(): void
    {
        DB::table('app_settings')->where('key', 'moneda_base')->delete();
    }

    public function down(): void
    {
        DB::table('app_settings')->insert([
            'group' => 'moneda',
            'key' => 'moneda_base',
            'value' => 'USD',
            'type' => 'string',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
