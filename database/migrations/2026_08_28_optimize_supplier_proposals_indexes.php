<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Índices ya existentes por columna, portable entre MySQL y SQLite
        // (tests): crear con try/catch en lugar de INFORMATION_SCHEMA, que
        // es exclusivo de MySQL y rompe la suite de tests en sqlite.
        $this->ensureIndex('supplier_material_proposals', ['project_id']);
        $this->ensureIndex('supplier_material_proposals', ['submitted_at']);
        $this->ensureIndex('supplier_material_proposal_lines', ['supplier_material_proposal_id']);
        $this->ensureIndex('supplier_material_proposal_lines', ['catalog_product_id']);
        $this->ensureIndex('supplier_material_proposal_lines', ['variation_percent', 'variation_direction']);
        // product_price_history ya tiene índices compuestos que cubren estas
        // columnas (idx_price_history_product_time, idx_price_history_product_supplier_time)
        // desde su migración de creación — no se duplican acá.
    }

    private function ensureIndex(string $table, array $columns): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                $blueprint->index($columns);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Índice ya existe (1061 en MySQL, "index already exists" en sqlite) — no-op.
        }
    }

    public function down(): void
    {
        Schema::table('supplier_material_proposals', function (Blueprint $table) {
            $table->dropIndex(['project_id']);
            $table->dropIndex(['submitted_at']);
        });

        Schema::table('supplier_material_proposal_lines', function (Blueprint $table) {
            $table->dropIndex(['supplier_material_proposal_id']);
            $table->dropIndex(['catalog_product_id']);
            $table->dropIndex(['variation_percent', 'variation_direction']);
        });
    }
};
