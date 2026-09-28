<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContractorDocumentTypeRequest;
use App\Http\Resources\ContractorDocumentTypeResource;
use App\Models\ConfigAuditLog;
use App\Models\ContractorDocumentType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Catálogo configurable de tipos de documento del proveedor (F4 Bloque A, D6). */
class ContractorDocumentTypeController extends Controller
{
    /**
     * GET /api/public/contractor-document-types — tipos activos que el
     * formulario público de registro debe pedir. Solo lectura y cacheado.
     */
    public function publicList()
    {
        $data = Cache::remember(
            ContractorDocumentType::CATALOG_CACHE_KEY,
            ContractorDocumentType::CATALOG_CACHE_TTL,
            fn () => ContractorDocumentTypeResource::collection(ContractorDocumentType::active()->get())->resolve()
        );

        return response()->json(['data' => $data]);
    }

    public function index()
    {
        $types = ContractorDocumentType::withCount('documents')->orderBy('sort_order')->orderBy('id')->get();

        return ContractorDocumentTypeResource::collection($types);
    }

    public function store(StoreContractorDocumentTypeRequest $request)
    {
        $data = $request->validated();

        $type = ContractorDocumentType::create([
            'key' => $this->uniqueKey($data['label']),
            'label' => strip_tags($data['label']),
            'is_required' => $data['isRequired'] ?? true,
            'is_active' => $data['isActive'] ?? true,
            'sort_order' => $data['sortOrder'] ?? ((int) ContractorDocumentType::max('sort_order') + 10),
        ]);
        ContractorDocumentType::forgetCatalogCache();

        $auditLog = ConfigAuditLog::recordAdminAction('contractor_document_type', 'Alta de tipo de documento de proveedor', null, null, "Tipo: {$type->label} / Obligatorio: " . ($type->is_required ? 'sí' : 'no'));

        return response()->json([
            ...(new ContractorDocumentTypeResource($type))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ], 201);
    }

    public function update(StoreContractorDocumentTypeRequest $request, ContractorDocumentType $contractorDocumentType)
    {
        $data = $request->validated();

        $contractorDocumentType->update([
            ...(isset($data['label']) ? ['label' => strip_tags($data['label'])] : []),
            ...(isset($data['isRequired']) ? ['is_required' => $data['isRequired']] : []),
            ...(isset($data['isActive']) ? ['is_active' => $data['isActive']] : []),
            ...(isset($data['sortOrder']) ? ['sort_order' => $data['sortOrder']] : []),
        ]);
        ContractorDocumentType::forgetCatalogCache();

        $auditLog = ConfigAuditLog::recordAdminAction('contractor_document_type', 'Modificacion de tipo de documento de proveedor', null, null, "Tipo: {$contractorDocumentType->label} / Obligatorio: " . ($contractorDocumentType->is_required ? 'sí' : 'no'));

        return response()->json([
            ...(new ContractorDocumentTypeResource($contractorDocumentType))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }

    public function toggleStatus(ContractorDocumentType $contractorDocumentType)
    {
        $contractorDocumentType->update(['is_active' => ! $contractorDocumentType->is_active]);
        ContractorDocumentType::forgetCatalogCache();

        $auditLog = ConfigAuditLog::recordAdminAction('contractor_document_type', 'Activacion/desactivacion de tipo de documento de proveedor', null, null, "Tipo: {$contractorDocumentType->label} / Activo: " . ($contractorDocumentType->is_active ? 'sí' : 'no'));

        return response()->json([
            'id' => $contractorDocumentType->id,
            'isActive' => $contractorDocumentType->is_active,
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }

    /** Con documentos cargados solo se desactiva, nunca se borra. */
    public function destroy(ContractorDocumentType $contractorDocumentType)
    {
        if ($contractorDocumentType->documents()->withTrashed()->exists()) {
            return response()->json(['message' => 'El tipo tiene documentos asociados: desactívelo en lugar de eliminarlo.'], 422);
        }

        $label = $contractorDocumentType->label;
        $contractorDocumentType->delete();
        ContractorDocumentType::forgetCatalogCache();

        $auditLog = ConfigAuditLog::recordAdminAction('contractor_document_type', 'Baja de tipo de documento de proveedor', null, null, "Tipo: {$label}");

        return response()->json(['auditLog' => $auditLog->toApiPayload()]);
    }

    private function uniqueKey(string $label): string
    {
        $base = Str::slug($label, '_') ?: 'documento';
        $key = $base;
        for ($i = 2; ContractorDocumentType::where('key', $key)->exists(); $i++) {
            $key = "{$base}_{$i}";
        }

        return $key;
    }
}
