<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogCategory;
use App\Models\ConfigAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Categorías del catálogo maestro de productos — exclusivo SUPERADMIN.
 * `spec_schema` define qué características técnicas pide/valida el
 * formulario de propuesta de cada proveedor según la categoría elegida
 * (cemento pide "resistencia_mpa", cable pide "calibre_awg", etc.) —
 * validado en la capa de aplicación al guardar cada línea, no en BD.
 */
class CatalogCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => CatalogCategory::orderBy('name')->get()]);
    }

    /**
     * Lista pública (sin auth) de categorías con su spec_schema — el
     * formulario del portal de proveedores la usa para saber qué
     * características técnicas pedir por línea según la categoría elegida.
     * Mismo shape que index(), sin restricción de rol: spec_schema no es
     * información sensible, es el propio formulario que el proveedor va a
     * completar.
     */
    public function publicList(): JsonResponse
    {
        return response()->json(['data' => CatalogCategory::orderBy('name')->get(['id', 'name', 'parent_id', 'spec_schema'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', 'exists:catalog_categories,id'],
            'spec_schema' => ['nullable', 'array'],
            'spec_schema.*.key' => ['required_with:spec_schema', 'string', 'max:60'],
            'spec_schema.*.label' => ['required_with:spec_schema', 'string', 'max:120'],
            'spec_schema.*.type' => ['required_with:spec_schema', 'string', 'in:text,number,boolean,select'],
            'spec_schema.*.unit' => ['nullable', 'string', 'max:20'],
            'spec_schema.*.required' => ['sometimes', 'boolean'],
        ]);

        $category = CatalogCategory::create($data);

        $auditLog = ConfigAuditLog::recordAdminAction('catalog_category', 'Alta de categoría de catálogo', null, null, "Categoría \"{$category->name}\" agregada.");

        return response()->json(['data' => [...$category->toArray(), 'auditLog' => $auditLog->toApiPayload()]], 201);
    }

    public function update(Request $request, CatalogCategory $catalogCategory): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:catalog_categories,id', 'not_in:' . $catalogCategory->id],
            'spec_schema' => ['sometimes', 'nullable', 'array'],
            'spec_schema.*.key' => ['required_with:spec_schema', 'string', 'max:60'],
            'spec_schema.*.label' => ['required_with:spec_schema', 'string', 'max:120'],
            'spec_schema.*.type' => ['required_with:spec_schema', 'string', 'in:text,number,boolean,select'],
            'spec_schema.*.unit' => ['nullable', 'string', 'max:20'],
            'spec_schema.*.required' => ['sometimes', 'boolean'],
        ]);

        $before = $catalogCategory->only(['name', 'parent_id']);
        $catalogCategory->update($data);
        $after = $catalogCategory->only(['name', 'parent_id']);

        $auditLog = ConfigAuditLog::recordAdminAction('catalog_category', 'Modificación de categoría de catálogo', json_encode($before), json_encode($after));

        return response()->json(['data' => [...$catalogCategory->toArray(), 'auditLog' => $auditLog->toApiPayload()]]);
    }

    public function destroy(CatalogCategory $catalogCategory): JsonResponse
    {
        abort_if($catalogCategory->children()->exists(), 422, 'No se puede eliminar una categoría con subcategorías.');
        abort_if($catalogCategory->products()->exists(), 422, 'No se puede eliminar una categoría con productos de catálogo asociados.');

        $name = $catalogCategory->name;
        $catalogCategory->delete();

        $auditLog = ConfigAuditLog::recordAdminAction('catalog_category', 'Eliminación de categoría de catálogo', $name, null, "Categoría \"{$name}\" eliminada.");

        return response()->json(['data' => ['auditLog' => $auditLog->toApiPayload()]]);
    }
}
