<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La orden de pago pasa a expresar la obligación en la moneda de cotización
 * (`amount` + `currency`, ej. 1.200 USDT). `amount_base` guarda el mismo
 * compromiso en la moneda base (USD): es lo que comparan el flujo de pago y
 * los agregados (semáforo, dashboards) que asumen montos en base. Las órdenes
 * existentes ya estaban en base, así que se rellenan con `amount`; su
 * snapshot/hash no se toca (es inmutable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->decimal('amount_base', 14, 2)->default(0)->after('amount');
        });

        DB::table('payment_orders')->update(['amount_base' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('payment_orders', function (Blueprint $table) {
            $table->dropColumn('amount_base');
        });
    }
};
