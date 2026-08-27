<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relación N:M "qué proveedores pueden ofrecer qué producto de
     * catálogo", con precio/fecha de última cotización desnormalizados a
     * propósito: es la tabla que consulta el submódulo de Presidencia para
     * listar catálogo por proveedor sin tener que agregar sobre
     * product_price_history en cada request.
     *
     * `supplier_code` referencia a `contractors.code` (Contractor es la
     * entidad "proveedor" existente en el sistema — no se crea una tabla
     * `suppliers` nueva).
     */
    public function up(): void
    {
        Schema::create('catalog_product_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_product_id')->constrained('material_catalog')->cascadeOnDelete();
            $table->string('supplier_code', 20);
            $table->timestamp('last_quoted_at');
            $table->decimal('last_quoted_price_usd', 18, 4);
            $table->unsignedInteger('quote_count')->default(0);
            $table->timestamps();

            $table->unique(['catalog_product_id', 'supplier_code'], 'uq_catalog_product_supplier');
            $table->index('last_quoted_at', 'idx_catalog_product_suppliers_last_quoted');
            $table->foreign('supplier_code')->references('code')->on('contractors');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_suppliers');
    }
};
