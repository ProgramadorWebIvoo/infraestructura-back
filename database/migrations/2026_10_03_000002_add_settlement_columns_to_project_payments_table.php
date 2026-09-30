<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pago registrado "como fue realizado": la obligación de la orden (moneda de
 * cotización) y lo que Finanzas efectivamente pagó — en la moneda original,
 * convertido a bolívares o a otra moneda — con la tasa real aplicada, la
 * tasa sugerida por el sistema en ese momento, el equivalente cubierto y la
 * diferencia (con motivo) contra la obligación. Más las referencias a las
 * tasas congeladas (adjudicación y pago) cuando la configuración las aplicó.
 *
 * `amount`/`currency` (moneda base) NO cambian: los agregados de
 * dashboards y semáforo dependen de ellos. Todas las columnas son nullable:
 * los pagos anteriores a esta migración no tienen liquidación registrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_payments', function (Blueprint $table) {
            // Obligación de la orden (moneda de cotización).
            $table->decimal('obligation_amount', 14, 2)->nullable()->after('currency');
            $table->string('obligation_currency', 10)->nullable()->after('obligation_amount');

            // QUOTE_CURRENCY | BS | OTHER_CURRENCY
            $table->string('payment_mode', 20)->nullable()->after('obligation_currency');
            // Moneda realmente pagada (Bs. = 'VES') y monto en esa moneda.
            $table->string('paid_currency', 10)->nullable()->after('payment_mode');
            $table->decimal('paid_amount', 18, 2)->nullable()->after('paid_currency');

            // Unidades de paid_currency por 1 unidad de obligation_currency.
            $table->decimal('applied_rate', 18, 8)->nullable()->after('paid_amount');
            $table->string('applied_rate_source', 10)->nullable()->after('applied_rate'); // BCV | USDT | MANUAL
            $table->decimal('suggested_rate', 18, 8)->nullable()->after('applied_rate_source');

            // paid_amount / applied_rate, en la moneda de la obligación, y su
            // diferencia contra la orden (con motivo si supera la tolerancia).
            $table->decimal('covered_amount', 14, 2)->nullable()->after('suggested_rate');
            $table->decimal('difference_amount', 14, 2)->nullable()->after('covered_amount');
            $table->string('difference_reason', 500)->nullable()->after('difference_amount');

            // Tasas congeladas vigentes: la de la adjudicación (cotización) y la del propio pago.
            $table->foreignId('contract_rate_freeze_id')->nullable()->after('difference_reason')
                ->constrained('project_rate_freezes')->nullOnDelete();
            $table->foreignId('payment_rate_freeze_id')->nullable()->after('contract_rate_freeze_id')
                ->constrained('project_rate_freezes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_rate_freeze_id');
            $table->dropConstrainedForeignId('contract_rate_freeze_id');
            $table->dropColumn([
                'obligation_amount',
                'obligation_currency',
                'payment_mode',
                'paid_currency',
                'paid_amount',
                'applied_rate',
                'applied_rate_source',
                'suggested_rate',
                'covered_amount',
                'difference_amount',
                'difference_reason',
            ]);
        });
    }
};
