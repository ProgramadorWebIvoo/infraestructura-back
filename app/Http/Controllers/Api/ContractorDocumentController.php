<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContractorDocumentRequest;
use App\Http\Resources\ContractorDocumentResource;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\ContractorDocument;
use App\Services\ContractorDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractorDocumentController extends Controller
{
    public function __construct(private readonly ContractorDocumentService $documents)
    {
    }

    public function index(Request $request, Contractor $contractor)
    {
        $query = $contractor->documents()->with('type');

        if (!$request->boolean('all_versions')) {
            $query->latestVersionOnly();
        }

        return response()->json([
            'data' => ContractorDocumentResource::collection($query->orderBy('document_type_id')->orderBy('version_number')->get()),
            'completeness' => $this->documents->completeness($contractor),
        ]);
    }

    public function store(StoreContractorDocumentRequest $request, Contractor $contractor)
    {
        $document = $this->documents->replace(
            $contractor,
            (int) $request->input('document_type_id'),
            $request->file('file'),
            ContractorDocument::SOURCE_INTERNAL,
        );
        $document->load('type');

        $action = $document->version_number > 1 ? 'Reemplazo de documento de proveedor' : 'Carga de documento de proveedor';
        AuditLog::record(null, auth()->user()->role, $action, "Proveedor: {$contractor->code} / {$document->type->label} / V{$document->version_number}: {$document->original_name}");

        return response()->json(['data' => new ContractorDocumentResource($document)], 201);
    }

    public function destroy(Contractor $contractor, ContractorDocument $document)
    {
        abort_unless($document->contractor_code === $contractor->code, 404);

        $document->load('type');
        $this->documents->removeGroup($document);

        AuditLog::record(null, auth()->user()->role, 'Eliminacion de documento de proveedor', "Proveedor: {$contractor->code} / {$document->type->label}: {$document->original_name}");

        return response()->json(['message' => 'Documento eliminado correctamente.']);
    }

    public function download(Contractor $contractor, ContractorDocument $document): StreamedResponse
    {
        abort_unless($document->contractor_code === $contractor->code, 404);
        abort_unless($this->documents->exists($document), 404, 'El archivo ya no existe en el servidor.');

        AuditLog::record(null, auth()->user()->role, 'Descarga de documento de proveedor', "Proveedor: {$contractor->code} / {$document->original_name}");

        return Storage::disk('local')->download($document->stored_path, $document->original_name, [
            'Content-Type' => $document->mime_type ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
