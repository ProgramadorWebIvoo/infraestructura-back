<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normaliza `supplier_material_proposals.items` (JSON libre) en filas
     * reales por línea. `items` en la cabecera se conserva tal cual como
     * snapshot de compatibilidad — lo que el proveedor vio y envió en el
     * momento — y esta tabla es la fuente de verdad estructurada que
     * alimenta catálogo + histórico de precios al confirmar la propuesta.
     * No se reescribe `items`, no se borra: evita romper cualquier lectura
     * existente que ya dependa de ese JSON.
     *
     * `catalog_product_id` NULL = producto personalizado ("a medida"), ver
     * create_custom_product_resolutions_table. El CHECK replica la regla de
     * negocio: toda línea debe poder identificar su producto de una forma
     * u otra, catalogado o declarado a mano.
     */
    public function up(): void
    {
        Schema::create('supplier_material_proposal_lines', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_material_proposal_id', 40);
            $table->foreignId('catalog_product_id')->nullable()->constrained('material_catalog');
            $table->string('custom_product_name', 255)->nullable();

            $table->enum('condition_status', ['new', 'used', 'refurbished'])->default('new');

            $table->string('quote_currency', 3);
            $table->decimal('fx_rate_to_usd', 18, 6);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('unit_price_usd', 18, 4);
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 30);

            $table->json('technical_specs');
            $table->string('warranty_description', 255)->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->string('image_path', 500)->nullable();
            $table->text('line_notes')->nullable();

            $table->timestamps();

            $table->foreign('supplier_material_proposal_id', 'fk_smpl_proposal')
                ->references('id')->on('supplier_material_proposals')->cascadeOnDelete();
            $table->index('supplier_material_proposal_id', 'idx_smpl_proposal');
            $table->index('catalog_product_id', 'idx_smpl_catalog_product');
        });

        // MySQL/MariaDB: CHECK no soportado como Blueprint fluido en todas las versiones del driver, se agrega crudo.
        // SQLite (tests): ALTER TABLE ADD CONSTRAINT no soportado — el CHECK solo se aplica en MySQL/MariaDB.
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'mysql') {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE `supplier_material_proposal_lines` ADD CONSTRAINT `chk_smpl_product_identity` ' .
                'CHECK (`catalog_product_id` IS NOT NULL OR `custom_product_name` IS NOT NULL)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_material_proposal_lines');
    }
};
