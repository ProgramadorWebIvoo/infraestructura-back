<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moneda única del pedido completo — antes cada línea (item) podía
     * declarar su propia moneda sin que existiera una moneda "principal"
     * del pedido, lo que no reflejaba la realidad de negocio: un proveedor
     * cotiza todo un pedido en UNA moneda. Default 'USD' para las filas
     * existentes (comportamiento previo, todo implícitamente USD).
     */
    public function up(): void
    {
        Schema::table('supplier_material_proposals', function (Blueprint $table) {
            $table->string('quote_currency', 3)->default('USD')->after('supplier_contact');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_material_proposals', function (Blueprint $table) {
            $table->dropColumn('quote_currency');
        });
    }
};
