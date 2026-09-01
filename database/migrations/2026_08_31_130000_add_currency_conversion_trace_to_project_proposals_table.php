<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            // Trazabilidad de conversión de moneda para propuestas importadas
            // del portal en una moneda distinta a la base (hoy USD). Todas
            // nullable: null en carga manual (siempre ya en la base) y en
            // propuestas del portal que ya vinieron en la moneda base — solo
            // se pueblan cuando SupplierProposalImportService realmente convirtió.
            //
            // material_cost/labor_cost/total_cost SIEMPRE quedan expresados en
            // la moneda base (igual que hoy) — el resto del sistema (semáforo
            // de presupuesto, comparativas, adjudicación) sigue leyéndolos sin
            // cambios. Estas columnas son el respaldo para mostrar "cotizado
            // originalmente en X" y para poder auditar/recalcular si la
            // tasa usada resultara cuestionada.
            $table->decimal('material_cost_original', 14, 4)->nullable()->after('quote_currency');
            $table->decimal('labor_cost_original', 14, 4)->nullable()->after('material_cost_original');
            $table->decimal('total_cost_original', 14, 4)->nullable()->after('labor_cost_original');

            // Tasa efectivamente usada para convertir quote_currency -> moneda
            // base en el momento del import (no la tasa de hoy) — permite
            // reconstruir exactamente el cálculo después, sin depender de que
            // la tasa histórica siga existiendo sin cambios en exchange_rates.
            $table->decimal('fx_rate_to_base', 14, 8)->nullable()->after('total_cost_original');

            // Qué moneda era la base del sistema en el momento del import.
            // Sin este dato, si la base cambia en el futuro (ej. USD -> EUR),
            // un registro viejo con solo fx_rate_to_base sería ambiguo: no se
            // podría saber contra qué base se calculó esa tasa. Con este
            // campo, el histórico completo sigue siendo interpretable
            // correctamente sin importar cuántas veces cambie la base futura.
            $table->string('base_currency_at_import', 3)->nullable()->after('fx_rate_to_base');
        });
    }

    public function down(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->dropColumn([
                'material_cost_original',
                'labor_cost_original',
                'total_cost_original',
                'fx_rate_to_base',
                'base_currency_at_import',
            ]);
        });
    }
};
