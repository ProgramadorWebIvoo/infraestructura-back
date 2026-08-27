<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsPublicAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupplierProposalResource;
use App\Models\SupplierInvitation;
use App\Models\SupplierMaterialProposal;
use App\Services\CatalogSyncService;
use App\Services\ProposalLineNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierProposalController extends Controller
{
    use LogsPublicAccess;

    public function __construct(
        private readonly ProposalLineNormalizer $lineNormalizer,
        private readonly CatalogSyncService $catalogSync,
    ) {
    }

    public function store(Request $request, string $token)
    {
        $invitation = SupplierInvitation::with('project')->find($token);
        if (!$invitation || !$invitation->isValid()) {
            return response()->json(['message' => 'Enlace no valido o expirado.'], 404);
        }

        $data = $request->validate([
            'estimatedDays'         => ['nullable', 'integer', 'min:1'],
            'durationUnit'          => ['nullable', 'string', 'in:dias,semanas,meses'],
            // Tope fijo (no el configurable de CONFIG APP): el proveedor externo
            // cotiza libremente su condición real de anticipo, sin conocer ni
            // estar limitado por la política interna de la empresa. El 100 es
            // solo una cota de sanidad contra valores absurdos (ej. 500%).
            'advancePercent'        => ['nullable', 'integer', 'min:0', 'max:100'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.materialName'  => ['required', 'string', 'max:220'],
            'items.*.quantity'      => ['required', 'numeric', 'min:0'],
            'items.*.unit'          => ['required', 'string', 'max:60'],
            'items.*.unitPrice'     => ['required', 'numeric', 'min:0'],
            'items.*.totalPrice'    => ['required', 'numeric', 'min:0'],
            'items.*.notes'         => ['nullable', 'string', 'max:500'],
            // Campos opcionales que alimentan el catálogo maestro y el
            // histórico de precios (ProposalLineNormalizer/CatalogSyncService)
            // — todavía no los envía el formulario público (Fase 3 pendiente),
            // se validan igual para no requerir tocar este endpoint cuando
            // el formulario los empiece a mandar.
            'items.*.quoteCurrency'      => ['nullable', 'string', 'size:3'],
            'items.*.conditionStatus'    => ['nullable', 'string', 'in:new,used,refurbished'],
            'items.*.catalogProductId'   => ['nullable', 'integer', 'exists:material_catalog,id'],
            'items.*.technicalSpecs'     => ['nullable', 'array'],
            'items.*.warrantyDescription' => ['nullable', 'string', 'max:255'],
            'items.*.warrantyMonths'     => ['nullable', 'integer', 'min:0', 'max:600'],
            'items.*.imagePath'          => ['nullable', 'string', 'max:500'],
            'generalNotes'          => ['nullable', 'string', 'max:1000'],
        ]);

        $proposal = DB::transaction(function () use ($token, $invitation, $data) {
            $proposal = SupplierMaterialProposal::create([
                'id'                     => SupplierMaterialProposal::nextId(),
                'invitation_token'       => $token,
                'project_id'             => $invitation->project_id,
                'project_title_snapshot' => $invitation->project->title,
                'supplier_name'          => $invitation->supplier_name,
                'supplier_company'       => $invitation->supplier_company,
                'supplier_contact'       => $invitation->supplier_contact,
                'items'                  => $data['items'],
                'general_notes'          => $data['generalNotes'] ?? null,
                'estimated_days'         => $data['estimatedDays'] ?? null,
                'duration_unit'          => $data['durationUnit'] ?? null,
                'advance_percent'        => $data['advancePercent'] ?? null,
            ]);

            // Marcar el enlace como usado (single-use)
            $invitation->update(['used_at' => now()]);

            return $proposal;
        });

        // Normalización de líneas + sincronización de catálogo/histórico:
        // fuera de la transacción principal a propósito. Si falla (ej. tasa
        // de cambio faltante para una moneda), la propuesta YA quedó
        // guardada y el enlace YA quedó marcado como usado — no queremos
        // que un problema de catálogo le devuelva un error al proveedor
        // externo ni lo deje reintentar con el mismo enlace de un solo uso.
        try {
            $lines = $this->lineNormalizer->normalize($proposal);
            $this->catalogSync->sync($proposal, $lines);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('No se pudo sincronizar catálogo/histórico para la propuesta.', [
                'proposal_id' => $proposal->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logPublicAccess($request, 'proposal.submit', "Propuesta: {$proposal->id} / Invitación: {$token} / Proveedor: {$invitation->supplier_name}", $invitation->project);

        return response()->json(new SupplierProposalResource($proposal), 201);
    }

    public function index(Request $request)
    {
        $perPage = min((int) ($request->get('per_page', 20)), 100);

        $query = SupplierMaterialProposal::latest('submitted_at');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        return response()->json($query->paginate($perPage)->through(fn ($p) => (new SupplierProposalResource($p))->resolve()));
    }
}
