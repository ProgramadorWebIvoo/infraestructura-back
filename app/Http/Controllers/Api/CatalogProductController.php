<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaterialCatalog;
use App\Models\ProductPriceHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo maestro consultable — submódulo de Presidencia. Lee
 * `material_catalog` (evolucionado con category_id/normalized_specs/
 * is_custom_origin) más el resumen desnormalizado de
 * `catalog_product_suppliers` (último precio/fecha por proveedor, sin
 * agregar sobre product_price_history en cada request).
 */
class CatalogProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MaterialCatalog::query()->with(['category', 'suppliers']);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('supplier_code')) {
            $supplierCode = $request->string('supplier_code');
            $query->whereHas('suppliers', fn ($q) => $q->where('supplier_code', $supplierCode));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $products = $query->orderBy('name')
            ->paginate(min((int) $request->get('per_page', 20), 100));

        return response()->json($products);
    }

    /**
     * Búsqueda pública (sin auth) del catálogo maestro — el portal de
     * proveedores la usa para que el proveedor pueda elegir "ya existe este
     * producto en catálogo" en vez de declarar todo como personalizado.
     * Solo id/name/unit/categoryId: nada de precios ni de qué proveedores
     * lo cotizan (eso es interno).
     */
    public function publicSearch(Request $request): JsonResponse
    {
        $search = trim((string) $request->get('search', ''));
        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $products = MaterialCatalog::where('is_active', true)
            ->where('name', 'like', "%{$search}%")
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'unit', 'category_id']);

        return response()->json(['data' => $products]);
    }

    public function show(MaterialCatalog $catalogProduct): JsonResponse
    {
        $catalogProduct->load(['category', 'suppliers.supplier']);

        return response()->json(['data' => $catalogProduct]);
    }

    /**
     * Serie temporal de precios de un producto — base de lectura para el
     * hito 3 (inflación), sin cálculo de inflación todavía. `supplier_code`
     * opcional filtra a un solo proveedor; sin filtro, trae todos.
     */
    public function priceHistory(Request $request, MaterialCatalog $catalogProduct): JsonResponse
    {
        $query = ProductPriceHistory::where('catalog_product_id', $catalogProduct->id)
            ->orderBy('quoted_at');

        if ($request->filled('supplier_code')) {
            $query->where('supplier_code', $request->string('supplier_code'));
        }

        if ($request->filled('from')) {
            $query->where('quoted_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->where('quoted_at', '<=', $request->date('to'));
        }

        return response()->json(['data' => $query->get()]);
    }
}
