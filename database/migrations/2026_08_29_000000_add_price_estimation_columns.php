<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega columnas para cálculo automático de EST (precio estimado) y variación.
     * Estas columnas se populan en el Observer al crear/actualizar línea de propuesta.
     *
     * - estimated_price_usd: Precio estimado en USD (promedio histórico o último cotizado)
     * - estimated_price_source: De dónde vino EST ('historical_avg' o 'last_quoted')
     * - variation_percent: Porcentaje de variación ((PROP - EST) / EST * 100)
     * - variation_direction: Dirección de variación ('increase', 'decrease', 'stable')
     *
     * Esta desnormalización permite:
     * 1. Lectura rápida: no calcular variación en cada request
     * 2. Reporting: filtrar/ordenar por variación sin joins complejos
     * 3. Alertas: identificar outliers (aumento >25%)
     */
    public function up(): void
    {
        Schema::table('supplier_material_proposal_lines', function (Blueprint $table) {
            $table->decimal('estimated_price_usd', 18, 4)
                ->nullable()
                ->after('unit_price_usd')
                ->comment('Precio estimado en USD (promedio histórico 6m o último cotizado)');

            $table->enum('estimated_price_source', ['historical_avg', 'last_quoted'])
                ->nullable()
                ->after('estimated_price_usd')
                ->comment("Fuente de EST: 'historical_avg' o 'last_quoted'");

            $table->decimal('variation_percent', 6, 2)
                ->nullable()
                ->after('estimated_price_source')
                ->comment('% Variación: ((PROP - EST) / EST) × 100');

            $table->enum('variation_direction', ['increase', 'decrease', 'stable'])
                ->nullable()
                ->after('variation_percent')
                ->comment("Dirección: 'increase' si >5%, 'decrease' si <-5%, 'stable' si ±5%");

            // Índice para queries de reportes (filtrar/ordenar por variación)
            $table->index('variation_percent', 'idx_smpl_variation_percent');
            $table->index('variation_direction', 'idx_smpl_variation_direction');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_material_proposal_lines', function (Blueprint $table) {
            $table->dropIndex('idx_smpl_variation_percent');
            $table->dropIndex('idx_smpl_variation_direction');
            $table->dropColumn([
                'estimated_price_usd',
                'estimated_price_source',
                'variation_percent',
                'variation_direction',
            ]);
        });
    }
};
