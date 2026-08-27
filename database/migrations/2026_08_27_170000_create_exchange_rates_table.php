<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico inmutable de tasas de cambio a USD — distinto de `currencies`
     * (catálogo estático de monedas habilitadas). Cada fila es un snapshot en
     * el tiempo; nunca se actualiza ni se borra una vez insertada, porque
     * `supplier_material_proposal_lines.fx_rate_to_usd` y
     * `product_price_history.fx_rate_to_usd` graban una copia de la tasa
     * vigente al momento de la cotización — el histórico de inflación
     * (hito 3) depende de que esa copia nunca cambie retroactivamente.
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency_code', 3);
            $table->decimal('rate_to_usd', 18, 6);
            $table->string('source', 50);
            $table->timestamp('effective_at');
            $table->timestamps();

            $table->index(['currency_code', 'effective_at'], 'idx_exchange_rates_currency_time');
            $table->foreign('currency_code')->references('code')->on('currencies');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
