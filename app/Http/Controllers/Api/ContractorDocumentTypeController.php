<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContractorDocumentTypeResource;
use App\Models\ContractorDocumentType;
use Illuminate\Support\Facades\Cache;

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
}
