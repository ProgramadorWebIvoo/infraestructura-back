<?php

namespace App\Observers;

use App\Models\ProjectProposal;
use App\Models\ProductPriceHistory;
use App\Support\CacheVersion;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza propuestas de proyectos (Analistas: MANUAL, RENEGOCIACION) a
 * product_price_history para que el histórico del proveedor sea completo.
 *
 * Las propuestas del portal (PORTAL-PROV) se sincronizan automáticamente vía
 * CatalogSyncService en SupplierMaterialProposalLine. Este observer agrega
 * el mismo mecanismo para propuestas de Analistas, preservando:
 * - price_usd (total_price de cada línea × fx_rate_to_base, convertido acá)
 * - original_currency (quoteCurrency de la propuesta)
 * - original_price (totalCostOriginal si existe, sino totalCost)
 * - fx_rate_to_usd (fxRateToBase o calculada)
 */
class ProjectProposalObserver
{
    public function created(ProjectProposal $proposal): void
    {
        $this->syncProposalToPriceHistory($proposal);
        $this->bumpContractorHistoryCache($proposal);
    }

    public function updated(ProjectProposal $proposal): void
    {
        // Resincronizar si cambió precio o moneda
        if ($proposal->isDirty(['total_cost', 'quote_currency', 'material_cost', 'labor_cost'])) {
            // Eliminar registros anteriores de esta propuesta
            ProductPriceHistory::where('project_proposal_id', $proposal->id)->delete();
            $this->syncProposalToPriceHistory($proposal);
            $this->bumpContractorHistoryCache($proposal);
        }
    }

    /**
     * Registra cada línea de material de la propuesta en product_price_history.
     * Si no hay materialItems (propuestas antiguas sin este campo),
     * registra una entrada por el monto total.
     */
    private function syncProposalToPriceHistory(ProjectProposal $proposal): void
    {
        if (!$proposal->contractor_code) {
            Log::warning("ProjectProposalObserver: propuesta {$proposal->id} sin contractor_code, omitiendo sincronización");
            return;
        }

        $quoteCurrency = $proposal->quote_currency ?? 'USD';
        $fxRateToUsd = $proposal->fx_rate_to_base ?? 1.0;
        $quotedAt = $proposal->fecha_oferta ?? $proposal->created_at;

        // Caso 1: Propuesta con material_items detallado (nuevo formato)
        if (is_array($proposal->material_items) && count($proposal->material_items) > 0) {
            foreach ($proposal->material_items as $item) {
                // item es ProposalMaterialItem: tiene catalogProductId, materialName, quantity, unitPrice, totalPrice
                $catalogProductId = $item['catalog_product_id'] ?? null;
                $originalPrice = (float) ($item['total_price'] ?? 0);

                $this->createPriceHistoryEntry(
                    catalogProductId: $catalogProductId !== null ? (int) $catalogProductId : null,
                    supplierCode: $proposal->contractor_code,
                    quantity: isset($item['quantity']) ? (float) $item['quantity'] : null,
                    priceUsd: $originalPrice * $fxRateToUsd,
                    originalCurrency: $quoteCurrency,
                    originalPrice: $originalPrice, // ← En moneda original
                    fxRateToUsd: $fxRateToUsd,
                    quotedAt: $quotedAt,
                    proposalId: $proposal->id,
                    proposalLineId: null, // No hay línea ID para Analistas
                    projectId: $proposal->project_id,
                );
            }
        } else {
            // Caso 2: Propuesta sin detalle de líneas (antiguas o simple)
            // Registrar monto total como una única entrada
            $this->createPriceHistoryEntry(
                catalogProductId: null, // No mapeable sin material_items
                supplierCode: $proposal->contractor_code,
                quantity: null,
                priceUsd: (float) $proposal->total_cost, // Ya en USD
                originalCurrency: $quoteCurrency,
                originalPrice: (float) ($proposal->total_cost_original ?? $proposal->total_cost),
                fxRateToUsd: $fxRateToUsd,
                quotedAt: $quotedAt,
                proposalId: $proposal->id,
                proposalLineId: null,
                projectId: $proposal->project_id,
            );
        }
    }

    /**
     * Crea o actualiza un registro en product_price_history.
     * Omite registros sin catalog_product_id (no se pueden hacer trending por producto).
     */
    private function createPriceHistoryEntry(
        ?int $catalogProductId,
        string $supplierCode,
        ?float $quantity,
        float $priceUsd,
        string $originalCurrency,
        float $originalPrice,
        float $fxRateToUsd,
        $quotedAt,
        string $proposalId,
        ?string $proposalLineId,
        ?string $projectId
    ): void {
        // Solo registrar si hay catalog_product_id (de otro modo no se puede hacer trending)
        if (!$catalogProductId) {
            return;
        }

        try {
            ProductPriceHistory::create([
                'catalog_product_id' => $catalogProductId,
                'supplier_code' => $supplierCode,
                'quantity' => $quantity,
                'supplier_material_proposal_line_id' => $proposalLineId,
                'project_proposal_id' => $proposalId,
                'price_usd' => $priceUsd,
                'original_currency' => $originalCurrency,
                'original_price' => $originalPrice,
                'fx_rate_to_usd' => $fxRateToUsd,
                'fx_rate_source' => 'PROJECT_PROPOSAL',
                'quoted_at' => $quotedAt,
                'origin' => 'PROJECT_PROPOSAL',
                'project_id' => $projectId,
            ]);
        } catch (\Exception $e) {
            Log::error("ProductPriceHistory sync failed for proposal {$proposalId}", [
                'error' => $e->getMessage(),
                'product_id' => $catalogProductId,
                'supplier' => $supplierCode,
            ]);
        }
    }

    /**
     * Invalida el caché del histórico del proveedor, mismo mecanismo que
     * PriceEstimationObserver para SupplierMaterialProposalLine.
     */
    private function bumpContractorHistoryCache(ProjectProposal $proposal): void
    {
        CacheVersion::bump('contractor_history:' . $proposal->contractor_code);
    }
}
