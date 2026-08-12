<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\LogsPublicAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupplierProposalResource;
use App\Models\SupplierInvitation;
use App\Models\SupplierMaterialProposal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierProposalController extends Controller
{
    use LogsPublicAccess;

    public function store(Request $request, string $token)
    {
        $invitation = SupplierInvitation::with('project')->find($token);
        if (!$invitation || !$invitation->isValid()) {
            return response()->json(['message' => 'Enlace no valido o expirado.'], 404);
        }

        $data = $request->validate([
            'estimatedDays'         => ['nullable', 'integer', 'min:1'],
            'durationUnit'          => ['nullable', 'string', 'in:dias,semanas,meses'],
            'advancePercent'        => ['nullable', 'integer', 'min:0', 'max:100'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.materialName'  => ['required', 'string', 'max:220'],
            'items.*.quantity'      => ['required', 'numeric', 'min:0'],
            'items.*.unit'          => ['required', 'string', 'max:60'],
            'items.*.unitPrice'     => ['required', 'numeric', 'min:0'],
            'items.*.totalPrice'    => ['required', 'numeric', 'min:0'],
            'items.*.notes'         => ['nullable', 'string', 'max:500'],
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
