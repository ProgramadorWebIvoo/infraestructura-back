<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Facades\DB;
use App\Models\ProjectProposal;
use App\Models\ProductPriceHistory;

/**
 * Rellena product_price_history con propuestas de proyectos existentes (MANUAL, RENEGOCIACION).
 * Las nuevas propuestas se sincronizan automáticamente vía ProjectProposalObserver, pero las
 * existentes antes de agregar el observer no están ahí — esta migración las agrega para que
 * el histórico sea completo.
 *
 * Solo procesa propuestas que ya no están soft-deleted (ismas se pueden ver en la UI).
 * Omite propuestas sin material_items o sin contractor_code.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->command->info("Sincronizando propuestas de proyectos existentes a product_price_history...");

        $count = 0;
        $skipped = 0;

        // Solo propuestas no soft-deleted, orígenes MANUAL y RENEGOCIACION
        $proposals = ProjectProposal::whereNotNull('contractor_code')
            ->whereIn('origen', ['MANUAL', 'RENEGOCIACION'])
            ->get();

        foreach ($proposals as $proposal) {
            // Omitir propuestas sin material_items
            if (!is_array($proposal->material_items) || count($proposal->material_items) === 0) {
                $skipped++;
                continue;
            }

            $quoteCurrency = $proposal->quote_currency ?? 'USD';
            $fxRateToUsd = $proposal->fx_rate_to_base ?? 1.0;
            $quotedAt = $proposal->fecha_oferta ?? $proposal->created_at;

            foreach ($proposal->material_items as $item) {
                // Omitir items sin catalog_product_id (no se pueden hacer trending sin ID)
                $catalogProductId = $item['catalog_product_id'] ?? null;
                if (!$catalogProductId) {
                    $skipped++;
                    continue;
                }

                // Evitar duplicados: si ya existe un entry para esta propuesta y producto, omitir
                $exists = ProductPriceHistory::where('project_proposal_id', $proposal->id)
                    ->where('catalog_product_id', $catalogProductId)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                try {
                    ProductPriceHistory::create([
                        'catalog_product_id' => $catalogProductId,
                        'supplier_code' => $proposal->contractor_code,
                        'supplier_material_proposal_line_id' => null,
                        'project_proposal_id' => $proposal->id,
                        'price_usd' => (float) ($item['total_price'] ?? 0),
                        'original_currency' => $quoteCurrency,
                        'original_price' => (float) ($item['total_price'] ?? 0),
                        'fx_rate_to_usd' => $fxRateToUsd,
                        'fx_rate_source' => 'PROJECT_PROPOSAL_BACKFILL',
                        'quoted_at' => $quotedAt,
                        'origin' => 'PROJECT_PROPOSAL',
                    ]);

                    $count++;
                } catch (\Exception $e) {
                    $this->command->warn("Error sincronizando propuesta {$proposal->id}: " . $e->getMessage());
                    $skipped++;
                }
            }
        }

        $this->command->info("Sincronización completada: $count registros creados, $skipped omitidos");
    }

    public function down(): void
    {
        $this->command->info("Eliminando registros backfill de project_proposals...");

        // Eliminar solo los que están marcados como BACKFILL
        ProductPriceHistory::where('fx_rate_source', 'PROJECT_PROPOSAL_BACKFILL')
            ->delete();

        $this->command->info("Eliminación completada");
    }
};
