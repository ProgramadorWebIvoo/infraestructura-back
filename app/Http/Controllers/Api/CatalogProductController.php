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
            $this->applyFulltextSearch($query, (string) $request->string('search'));
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

        $query = MaterialCatalog::where('is_active', true);
        $this->applyFulltextSearch($query, $search);

        $products = $query->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'unit', 'category_id']);

        return response()->json(['data' => $products]);
    }

    /**
     * `LIKE '%...%'` sobre `name` forzaba un full table scan en cada
     * búsqueda de catálogo (incluida publicSearch(), sin auth) pese a que la
     * migración 2026_08_27_170200 ya creó el índice FULLTEXT
     * `ft_material_catalog_name` — nunca se usaba (auditoría de rendimiento
     * 2026-09-16). BOOLEAN MODE con sufijo `*` por palabra imita la UX de
     * "contiene" de LIKE para prefijos de palabra (no substring interno);
     * los operadores booleanos de FULLTEXT se limpian del input del usuario
     * para que un símbolo como `+`/`"` no cambie el significado de la
     * búsqueda sin que el usuario lo sepa. Nota: InnoDB no indexa palabras
     * de menos de 3 caracteres (innodb_ft_min_token_size por defecto).
     *
     * MATCH...AGAINST es sintaxis MySQL — los tests corren contra SQLite en
     * memoria (phpunit.xml), que no la soporta, así que se degrada a LIKE
     * fuera de MySQL en vez de romper la suite.
     */
    private function applyFulltextSearch($query, string $search): void
    {
        $words = preg_split('/\s+/', preg_replace('/[+\-<>()~*"@]+/', ' ', trim($search)), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return;
        }

        if ($query->getConnection()->getDriverName() !== 'mysql') {
            foreach ($words as $word) {
                $query->where('name', 'like', "%{$word}%");
            }
            return;
        }

        $booleanQuery = implode(' ', array_map(fn ($w) => '+' . $w . '*', $words));
        $query->whereRaw('MATCH(name) AGAINST(? IN BOOLEAN MODE)', [$booleanQuery]);
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
