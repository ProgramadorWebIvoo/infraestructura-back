<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Chequear si los índices ya existen antes de crearlos
        $this->ensureIndexExists('supplier_material_proposals', 'project_id');
        $this->ensureIndexExists('supplier_material_proposals', 'submitted_at');
        $this->ensureIndexExists('supplier_material_proposal_lines', 'supplier_material_proposal_id');
        $this->ensureIndexExists('supplier_material_proposal_lines', 'catalog_product_id');
        $this->ensureIndexExists('supplier_material_proposal_lines', ['variation_percent', 'variation_direction']);
        $this->ensureIndexExists('product_price_history', 'supplier_code');
        $this->ensureIndexExists('product_price_history', 'catalog_product_id');
    }

    private function ensureIndexExists(string $table, string|array $columns): void
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $columnList = implode(',', $columns);

        $result = DB::selectOne(
            "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND COLUMN_NAME IN ('" . implode("','", $columns) . "')",
            [$table]
        );

        if (!$result) {
            Schema::table($table, function (Blueprint $table) use ($columns) {
                if (count($columns) === 1) {
                    $table->index($columns[0]);
                } else {
                    $table->index($columns);
                }
            });
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

        Schema::table('product_price_history', function (Blueprint $table) {
            $table->dropIndex(['supplier_code']);
            $table->dropIndex(['catalog_product_id']);
        });
    }
};
