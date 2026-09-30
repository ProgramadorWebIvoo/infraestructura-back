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

    /**
     * Solo reversible si no hay datos con códigos de más de 3 letras: se
     * eliminan primero las tasas y la moneda USDT sembradas por esta
     * funcionalidad para que el acortado no falle por truncamiento.
     */
    public function down(): void
    {
        DB::table('exchange_rates')->whereRaw('CHAR_LENGTH(currency_code) > 3')->delete();
        DB::table('currencies')->whereRaw('CHAR_LENGTH(code) > 3')->delete();

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
