<?php

namespace App\Observers;

use App\Models\SupplierMaterialProposalLine;
use App\Models\Contractor;
use App\Services\PriceEstimationService;
use App\Support\CacheVersion;

class PriceEstimationObserver
{
    /** Profundidad de lotes abiertos (ver batch()). Estático: Laravel crea una instancia del observador por evento. */
    private static int $batchDepth = 0;

    /** Namespaces de caché pendientes de invalidar al cerrar el lote. */
    private static array $pendingNamespaces = [];

    /** Nombres de proveedor cuyo histórico hay que invalidar al cerrar el lote. */
    private static array $pendingSupplierNames = [];

    /** Código de proveedor por nombre, memoizado mientras dura el lote. */
    private static array $supplierCodeMemo = [];

    /** Estimación de precio por producto+proveedor, memoizada mientras dura el lote. */
    private static array $estimateMemo = [];

    public function __construct(private PriceEstimationService $priceService) {}

    /**
     * Ejecuta `$callback` agrupando las invalidaciones de caché: crear N líneas de una misma
     * propuesta invalida cada namespace UNA vez al terminar, en lugar de N veces (cada
     * invalidación es una lectura + una escritura en la tabla `cache`; con 70 líneas eran
     * unas 350 consultas solo para eso). El resultado final es idéntico: la versión de caché
     * cambia antes de que nadie lea con la versión nueva.
     */
    public static function batch(callable $callback): mixed
    {
        self::$batchDepth++;
        try {
            return $callback();
        } finally {
            self::$batchDepth--;
            if (self::$batchDepth === 0) {
                self::flushPending();
            }
        }
    }

    private static function flushPending(): void
    {
        $namespaces = self::$pendingNamespaces;
        foreach (array_keys(self::$pendingSupplierNames) as $name) {
            $namespaces['contractor_history:' . (Contractor::codeForSupplierName($name) ?? $name)] = true;
        }
        self::$pendingNamespaces = [];
        self::$pendingSupplierNames = [];
        self::$supplierCodeMemo = [];
        self::$estimateMemo = [];

        foreach (array_keys($namespaces) as $namespace) {
            CacheVersion::bump($namespace);
        }
    }

    /**
     * Calcula EST y variación cuando se crea o actualiza una línea de propuesta.
     */
    public function creating(SupplierMaterialProposalLine $line): void
    {
        $this->calculateEstimation($line);
        // Invalidar caché general de propuestas cuando se crean líneas
        $this->bump('supplier_proposals');
        $this->bumpContractorHistoryCache($line);
    }

    public function updating(SupplierMaterialProposalLine $line): void
    {
        // Recalcular si cambió precio o moneda
        if ($line->isDirty(['unit_price_usd', 'unit_price', 'quote_currency'])) {
            $this->calculateEstimation($line);
            $this->bump('supplier_proposals');
            $this->bumpContractorHistoryCache($line);
        }
    }

    private function bump(string $namespace): void
    {
        if (self::$batchDepth > 0) {
            self::$pendingNamespaces[$namespace] = true;

            return;
        }

        CacheVersion::bump($namespace);
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

        if (self::$batchDepth > 0) {
            // El código de proveedor se resuelve una vez por nombre al cerrar el lote.
            self::$pendingSupplierNames[$line->proposal->supplier_name] = true;

            return;
        }

        CacheVersion::bump('contractor_history:' . $this->supplierCodeFor($line));
    }

    /** Código del contratista por nombre del proveedor (o el propio nombre si aún no está registrado). */
    private function supplierCodeFor(SupplierMaterialProposalLine $line): string
    {
        $name = $line->proposal->supplier_name;

        if (self::$batchDepth > 0) {
            return self::$supplierCodeMemo[$name] ??= Contractor::codeForSupplierName($name) ?? $name;
        }

        return Contractor::codeForSupplierName($name) ?? $name;
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
        $supplierCode = $this->supplierCodeFor($line);

        // Obtener EST del servicio. Dentro de un lote (las líneas de una misma propuesta) el historial
        // no cambia entre línea y línea, así que se consulta una vez por producto+proveedor.
        $months = config('pricing.price_estimation.historical_months', 6);
        if (self::$batchDepth > 0) {
            $memoKey = $line->catalog_product_id . '|' . $supplierCode;
            if (!array_key_exists($memoKey, self::$estimateMemo)) {
                self::$estimateMemo[$memoKey] = $this->priceService->getEstimatedPrice($line->catalog_product_id, $supplierCode, $months);
            }
            $est = self::$estimateMemo[$memoKey];
        } else {
            $est = $this->priceService->getEstimatedPrice($line->catalog_product_id, $supplierCode, $months);
        }

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
