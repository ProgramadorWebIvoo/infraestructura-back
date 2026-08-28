<?php

namespace App\Observers;

use App\Models\SupplierMaterialProposalLine;
use App\Models\Contractor;
use App\Services\PriceEstimationService;

class PriceEstimationObserver
{
    public function __construct(private PriceEstimationService $priceService) {}

    /**
     * Calcula EST y variación cuando se crea o actualiza una línea de propuesta.
     */
    public function creating(SupplierMaterialProposalLine $line): void
    {
        $this->calculateEstimation($line);
        // Invalidar caché general de propuestas cuando se crean líneas
        \App\Support\CacheVersion::bump('supplier_proposals');
        $this->bumpContractorHistoryCache($line);
    }

    public function updating(SupplierMaterialProposalLine $line): void
    {
        // Recalcular si cambió precio o moneda
        if ($line->isDirty(['unit_price_usd', 'unit_price', 'quote_currency'])) {
            $this->calculateEstimation($line);
            \App\Support\CacheVersion::bump('supplier_proposals');
            $this->bumpContractorHistoryCache($line);
        }
    }

    /**
     * Invalida el histórico consolidado del proveedor (ContractorHistoryService)
     * cuando entra una cotización nueva o cambia de precio — el mismo evento
     * que ya escribe en product_price_history vía CatalogSyncService.
     */
    private function bumpContractorHistoryCache(SupplierMaterialProposalLine $line): void
    {
        if (!$line->relationLoaded('proposal')) {
            $line->load('proposal');
        }

        $supplierCode = Contractor::codeForSupplierName($line->proposal->supplier_name) ?? $line->proposal->supplier_name;
        \App\Support\CacheVersion::bump('contractor_history:' . $supplierCode);
    }

    /**
     * Calcula EST, variation_percent y variation_direction.
     */
    private function calculateEstimation(SupplierMaterialProposalLine $line): void
    {
        // Solo si es producto del catálogo (no personalizado)
        if (!$line->catalog_product_id) {
            return;
        }

        // Cargar la propuesta si no está cargada
        if (!$line->relationLoaded('proposal')) {
            $line->load('proposal');
        }

        // Resolver supplier_code por nombre (cacheado, case-insensitive) —
        // ver Contractor::codeForSupplierName(), fuente única compartida
        // con CatalogSyncService para que ambos resuelvan igual.
        $supplierCode = Contractor::codeForSupplierName($line->proposal->supplier_name) ?? $line->proposal->supplier_name;

        // Obtener EST del servicio
        $est = $this->priceService->getEstimatedPrice(
            $line->catalog_product_id,
            $supplierCode,
            config('pricing.price_estimation.historical_months', 6)
        );

        // Si no hay EST, limpiar datos de variación
        if (!$est) {
            $line->estimated_price_usd = null;
            $line->estimated_price_source = null;
            $line->variation_percent = null;
            $line->variation_direction = null;
            return;
        }

        // Guardar EST
        $line->estimated_price_usd = $est->value;
        $line->estimated_price_source = $est->source;

        // Calcular variación %
        $line->variation_percent = (($line->unit_price_usd - $est->value) / $est->value) * 100;

        // Determinar dirección (threshold = 5%)
        $threshold = 5;
        if ($line->variation_percent > $threshold) {
            $line->variation_direction = 'increase';
        } elseif ($line->variation_percent < -$threshold) {
            $line->variation_direction = 'decrease';
        } else {
            $line->variation_direction = 'stable';
        }
    }
}
