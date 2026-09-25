<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F3 — `contracted_quantity` pasa a ser la cantidad vigente (contratado + modificaciones
 * aprobadas); `original_quantity` conserva lo contratado al adjudicar, para mostrar
 * original / modificación / final en el formato de cierre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_closure_report_items', function (Blueprint $table) {
            $table->decimal('original_quantity', 14, 2)->nullable()->after('contracted_quantity');
        });

        DB::table('project_closure_report_items')->update(['original_quantity' => DB::raw('contracted_quantity')]);
    }

    public function down(): void
    {
        Schema::table('project_closure_report_items', function (Blueprint $table) {
            $table->dropColumn('original_quantity');
        });
    }
};
