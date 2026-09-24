<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rellena quantity/project_id de filas de product_price_history creadas
 * antes de que estas columnas existieran (ver migración anterior).
 *
 * - origin=PORTAL_PROV: quantity/project_id vienen de la línea de propuesta
 *   (supplier_material_proposal_lines.quantity) y de la propuesta del portal
 *   (supplier_material_proposals.project_id) — vínculo directo por FK.
 * - origin=PROJECT_PROPOSAL: project_id viene de project_proposals.project_id
 *   (directo). quantity no se puede recuperar con certeza para filas viejas
 *   sin material_items detallado (ver ProjectProposalObserver caso 2) — se
 *   intenta matchear por catalog_product_id dentro de material_items cuando
 *   hay uno solo con ese producto; si hay ambigüedad (mismo producto
 *   cotizado más de una vez en la misma propuesta) se deja null en vez de
 *   adivinar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfillPortalProv();
        $this->backfillProjectProposal();
    }

    private function backfillPortalProv(): void
    {
        $rows = DB::table('product_price_history as pph')
            ->join('supplier_material_proposal_lines as smpl', 'smpl.id', '=', 'pph.supplier_material_proposal_line_id')
            ->join('supplier_material_proposals as smp', 'smp.id', '=', 'smpl.supplier_material_proposal_id')
            ->whereNull('pph.quantity')
            ->select('pph.id', 'smpl.quantity', 'smp.project_id')
            ->get();

        foreach ($rows as $row) {
            DB::table('product_price_history')
                ->where('id', $row->id)
                ->update(['quantity' => $row->quantity, 'project_id' => $row->project_id]);
        }

        Log::info('Backfill PORTAL_PROV product_price_history: ' . $rows->count() . ' filas actualizadas');
    }

    private function backfillProjectProposal(): void
    {
        $proposalRows = DB::table('product_price_history')
            ->where('origin', 'PROJECT_PROPOSAL')
            ->whereNotNull('project_proposal_id')
            ->whereNull('project_id')
            ->select('id', 'catalog_product_id', 'project_proposal_id')
            ->get()
            ->groupBy('project_proposal_id');

        $updated = 0;

        foreach ($proposalRows as $proposalId => $historyRows) {
            $proposal = DB::table('project_proposals')->where('id', $proposalId)->first(['project_id', 'material_items']);
            if (!$proposal) {
                continue;
            }

            $items = json_decode($proposal->material_items ?? '[]', true) ?: [];
            $countByProduct = [];
            foreach ($items as $item) {
                $pid = $item['catalog_product_id'] ?? null;
                if ($pid !== null) {
                    $countByProduct[$pid] = ($countByProduct[$pid] ?? 0) + 1;
                }
            }

            $quantityByProduct = [];
            foreach ($items as $item) {
                $pid = $item['catalog_product_id'] ?? null;
                if ($pid !== null && ($countByProduct[$pid] ?? 0) === 1) {
                    $quantityByProduct[$pid] = $item['quantity'] ?? null;
                }
            }

            foreach ($historyRows as $historyRow) {
                DB::table('product_price_history')
                    ->where('id', $historyRow->id)
                    ->update([
                        'project_id' => $proposal->project_id,
                        'quantity' => $quantityByProduct[$historyRow->catalog_product_id] ?? null,
                    ]);
                $updated++;
            }
        }

        Log::info("Backfill PROJECT_PROPOSAL product_price_history: {$updated} filas actualizadas");
    }

    public function down(): void
    {
        // No reversible de forma segura (no se puede distinguir un valor
        // backfillado de uno grabado nativamente tras esta migración) —
        // intencional, igual que otras migraciones de backfill del repo.
    }
};
