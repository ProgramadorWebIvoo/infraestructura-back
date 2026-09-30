<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Amplía de 3 a 10 caracteres toda columna que guarda un código de
     * moneda, para poder alojar "USDT" (4 letras, no ISO 4217). La única FK
     * hacia `currencies.code` es la de `exchange_rates`: se suelta antes de
     * tocar ambos lados y se recrea después. El resto de columnas
     * (quote_currency, base_currency, etc.) son strings sin FK, pero deben
     * poder guardar el mismo código para cuando USDT se cotice.
     */
    public function up(): void
    {
        Schema::table('exchange_rates', fn (Blueprint $table) => $table->dropForeign(['currency_code']));

        Schema::table('currencies', fn (Blueprint $table) => $table->string('code', 10)->change());
        Schema::table('exchange_rates', fn (Blueprint $table) => $table->string('currency_code', 10)->change());

        Schema::table('exchange_rates', fn (Blueprint $table) => $table
            ->foreign('currency_code')->references('code')->on('currencies'));

        Schema::table('supplier_material_proposals', fn (Blueprint $table) => $table->string('quote_currency', 10)->default('USD')->change());
        Schema::table('supplier_material_proposal_lines', fn (Blueprint $table) => $table->string('quote_currency', 10)->change());
        Schema::table('product_price_history', fn (Blueprint $table) => $table->string('original_currency', 10)->change());
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->string('quote_currency', 10)->nullable()->change();
            $table->string('base_currency_at_import', 10)->nullable()->change();
        });
        Schema::table('project_rate_freezes', fn (Blueprint $table) => $table->string('base_currency', 10)->change());
        Schema::table('project_payments', fn (Blueprint $table) => $table->string('currency', 10)->default('USD')->change());
    }

    /** [tabla, columna] de todo código de moneda ampliado por esta migración. */
    private const CODE_COLUMNS = [
        ['currencies', 'code'],
        ['exchange_rates', 'currency_code'],
        ['supplier_material_proposals', 'quote_currency'],
        ['supplier_material_proposal_lines', 'quote_currency'],
        ['product_price_history', 'original_currency'],
        ['project_proposals', 'quote_currency'],
        ['project_proposals', 'base_currency_at_import'],
        ['project_rate_freezes', 'base_currency'],
        ['project_payments', 'currency'],
    ];

    /**
     * Solo reversible si ningún dato usa ya códigos de más de 3 caracteres
     * (USDT sembrada, tasas o cotizaciones en USDT): acortar la columna
     * fallaría con "Data too long" o, peor, habría que borrar histórico
     * fiscal. En ese caso aborta ANTES de tocar nada (el DDL de MySQL no es
     * transaccional, un fallo a medias dejaría el esquema inconsistente).
     */
    public function down(): void
    {
        foreach (self::CODE_COLUMNS as [$table, $column]) {
            if (DB::table($table)->whereRaw("LENGTH({$column}) > 3")->exists()) {
                throw new \RuntimeException(
                    "No se puede revertir: {$table}.{$column} contiene códigos de más de 3 caracteres (ej. USDT). "
                    . 'Elimina o migra esos datos manualmente antes de hacer rollback.'
                );
            }
        }

        Schema::table('exchange_rates', fn (Blueprint $table) => $table->dropForeign(['currency_code']));

        Schema::table('currencies', fn (Blueprint $table) => $table->string('code', 3)->change());
        Schema::table('exchange_rates', fn (Blueprint $table) => $table->string('currency_code', 3)->change());

        Schema::table('exchange_rates', fn (Blueprint $table) => $table
            ->foreign('currency_code')->references('code')->on('currencies'));

        Schema::table('supplier_material_proposals', fn (Blueprint $table) => $table->string('quote_currency', 3)->default('USD')->change());
        Schema::table('supplier_material_proposal_lines', fn (Blueprint $table) => $table->string('quote_currency', 3)->change());
        Schema::table('product_price_history', fn (Blueprint $table) => $table->string('original_currency', 3)->change());
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->string('quote_currency', 3)->nullable()->change();
            $table->string('base_currency_at_import', 3)->nullable()->change();
        });
        Schema::table('project_rate_freezes', fn (Blueprint $table) => $table->string('base_currency', 3)->change());
        Schema::table('project_payments', fn (Blueprint $table) => $table->string('currency', 3)->default('USD')->change());
    }
};
