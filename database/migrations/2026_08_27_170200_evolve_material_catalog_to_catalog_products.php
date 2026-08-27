<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `material_catalog` era un listado plano (name/unit/precio estimado
     * único) sin categoría, specs ni precio-por-proveedor. Se extiende en
     * vez de reemplazar — sigue siendo la tabla de catálogo maestro, ahora
     * con lo necesario para el hito de estadísticas de inflación:
     * `category_id` (para agrupar por spec_schema), `normalized_specs`
     * (versión "limpia" consolidada por Presidencia, distinta del
     * `technical_specs` crudo declarado por cada proveedor en su línea) e
     * `is_custom_origin` (marca productos que nacieron de un ítem
     * personalizado de un proveedor, ver create_custom_product_resolutions).
     *
     * `estimated_unit_price` (columna original) se mantiene tal cual: sigue
     * siendo el precio de referencia usado hoy en Procura/presupuestos, no
     * lo reemplaza `catalog_product_suppliers.last_quoted_price_usd` (ese es
     * precio real cotizado por proveedor, este es una estimación editorial).
     */
    public function up(): void
    {
        Schema::table('material_catalog', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('id')
                ->constrained('catalog_categories')->nullOnDelete();
            $table->json('normalized_specs')->nullable()->after('estimated_unit_price');
            $table->boolean('is_custom_origin')->default(false)->after('normalized_specs');
            $table->softDeletes();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `material_catalog` ADD FULLTEXT `ft_material_catalog_name` (`name`)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `material_catalog` DROP INDEX `ft_material_catalog_name`');
        }

        Schema::table('material_catalog', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['normalized_specs', 'is_custom_origin']);
            $table->dropSoftDeletes();
        });
    }
};
