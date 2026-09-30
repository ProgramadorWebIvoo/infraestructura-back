<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se congela son los BOLÍVARES: la moneda del monto (la de cotización,
 * ej. USDT), el monto en esa moneda, la tasa de ESA moneda (Bs. por unidad) y
 * los Bs. resultantes. `frozen_rate` pasa a significar "Bs. por unidad de
 * `frozen_currency`" (antes, siempre por unidad de la moneda base);
 * `base_currency` y `frozen_amount_base` se conservan (equivalente en base).
 *
 * Las filas existentes eran siempre en moneda base: se rellenan con
 * `frozen_currency = base_currency`, `frozen_amount = frozen_amount_base` y
 * `frozen_amount_bs = frozen_amount_base × frozen_rate` (donde hay ambos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_rate_freezes', function (Blueprint $table) {
            $table->string('frozen_currency', 10)->nullable()->after('base_currency');
            $table->decimal('frozen_amount', 18, 4)->nullable()->after('frozen_amount_base');
            $table->decimal('frozen_amount_bs', 20, 2)->nullable()->after('frozen_amount');
        });

        DB::table('project_rate_freezes')->update([
            'frozen_currency' => DB::raw('base_currency'),
            'frozen_amount' => DB::raw('frozen_amount_base'),
        ]);

        DB::table('project_rate_freezes')
            ->whereNotNull('frozen_amount_base')
            ->whereNotNull('frozen_rate')
            ->update(['frozen_amount_bs' => DB::raw('ROUND(frozen_amount_base * frozen_rate, 2)')]);
    }

    public function down(): void
    {
        Schema::table('project_rate_freezes', function (Blueprint $table) {
            $table->dropColumn(['frozen_currency', 'frozen_amount', 'frozen_amount_bs']);
        });
    }
};
