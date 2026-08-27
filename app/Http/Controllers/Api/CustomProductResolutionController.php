<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CustomProductResolution;
use App\Models\MaterialCatalog;
use App\Models\SupplierMaterialProposalLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Panel de Presidencia para reclasificar líneas de propuesta con producto
 * "a medida" (custom_product_name, sin catalog_product_id resuelto a un
 * producto ya existente en catálogo) hacia el producto de catálogo
 * correcto. No reescribe la línea original ni el ProductPriceHistory ya
 * grabado con el catalog_product_id resuelto en el momento del submit
 * (CatalogSyncService::resolveOrCreateFromCustom) — es una decisión
 * administrativa posterior, auditable por separado, que no altera hechos
 * históricos ya persistidos.
 */
class CustomProductResolutionController extends Controller
{
    /** Líneas cuyo producto de catálogo nació de un item personalizado (is_custom_origin) y aún no fue revisado. */
    public function pending(): JsonResponse
    {
        $lines = SupplierMaterialProposalLine::query()
            ->whereHas('catalogProduct', fn ($q) => $q->where('is_custom_origin', true))
            ->whereDoesntHave('customResolution')
            ->with(['catalogProduct', 'proposal:id,supplier_name,supplier_company,project_id,submitted_at'])
            ->orderByDesc('created_at')
            ->paginate(min((int) request('per_page', 20), 100));

        return response()->json($lines);
    }

    public function store(Request $request, SupplierMaterialProposalLine $line): JsonResponse
    {
        $data = $request->validate([
            'resolved_catalog_product_id' => ['required', 'integer', 'exists:material_catalog,id'],
        ]);

        abort_if($line->customResolution()->exists(), 422, 'Esta línea ya fue reclasificada.');
        abort_if((int) $data['resolved_catalog_product_id'] === (int) $line->catalog_product_id, 422, 'El producto seleccionado es el mismo que ya tiene la línea.');

        $resolution = DB::transaction(function () use ($line, $data) {
            return CustomProductResolution::create([
                'supplier_material_proposal_line_id' => $line->id,
                'resolved_catalog_product_id' => $data['resolved_catalog_product_id'],
                'resolved_by' => auth()->id(),
                'resolved_at' => now(),
            ]);
        });

        $target = MaterialCatalog::find($data['resolved_catalog_product_id']);
        AuditLog::record(
            null,
            'PRESIDENCIA',
            'Reclasificación de producto personalizado',
            "Línea {$line->id} (\"{$line->custom_product_name}\") vinculada a \"{$target->name}\" (#{$target->id})."
        );

        return response()->json(['data' => $resolution->load(['proposalLine', 'resolvedProduct'])], 201);
    }
}
