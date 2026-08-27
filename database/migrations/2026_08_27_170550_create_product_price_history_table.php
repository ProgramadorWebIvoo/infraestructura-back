<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log append-only de precios cotizados, normalizados a USD. Nunca se
     * actualiza ni se borra una fila existente — es la fuente de datos del
     * hito 3 (estadísticas/inflación). El índice compuesto
     * (catalog_product_id, quoted_at) es el que sostiene las consultas de
     * serie temporal por producto sin joins a monedas en tiempo de lectura,
     * porque `price_usd`/`fx_rate_to_usd` ya son un snapshot grabado en el
     * momento de la cotización (ver create_exchange_rates_table).
     */
    public function up(): void
    {
        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_product_id')->constrained('material_catalog');
            $table->string('supplier_code', 20);
            $table->foreignId('supplier_material_proposal_line_id');
            $table->foreign('supplier_material_proposal_line_id', 'fk_pph_proposal_line')
                ->references('id')->on('supplier_material_proposal_lines');

            $table->decimal('price_usd', 18, 4);
            $table->string('original_currency', 3);
            $table->decimal('original_price', 18, 4);
            $table->decimal('fx_rate_to_usd', 18, 6);
            $table->string('fx_rate_source', 50);

            $table->timestamp('quoted_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('supplier_code')->references('code')->on('contractors');
            $table->index(['catalog_product_id', 'quoted_at'], 'idx_price_history_product_time');
            $table->index(['catalog_product_id', 'supplier_code', 'quoted_at'], 'idx_price_history_product_supplier_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_history');
    }
};
