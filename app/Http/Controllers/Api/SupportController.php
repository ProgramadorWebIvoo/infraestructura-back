<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\MaterialCatalog;
use App\Models\Project;
use App\Models\SupplierInvitation;
use App\Models\SupplierMaterialProposal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupportController extends Controller
{
    public function modules()
    {
        return DB::table('app_modules')->orderBy('id')->get();
    }

    public function contractors()
    {
        return Contractor::where('status', 'ACTIVE')
            ->orderBy('name')
            ->get(['code', 'name', 'specialty', 'rating', 'contact', 'status']);
    }

    public function storeContractor(Request $request)
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:30', 'unique:contractors,code'],
            'name' => ['required', 'string', 'max:180'],
            'specialty' => ['required', 'string', 'max:180'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'contact' => ['required', 'string', 'max:180'],
        ]);

        // Sanitización server-side: eliminar etiquetas HTML/XML de campos de texto
        $data['name'] = strip_tags($data['name']);
        $data['specialty'] = strip_tags($data['specialty']);
        $data['contact'] = strip_tags($data['contact']);

        $contractor = DB::transaction(function () use ($data) {
            $data['code'] ??= Contractor::nextCode();
            $data['rating'] ??= 4.0;
            $data['registration_source'] = 'PUBLIC_PORTAL';
            $data['status'] = 'PENDING_REVIEW';

            return Contractor::create($data);
        });

        $this->logPublicAccess($request, 'contractor.register', "Proveedor: {$contractor->name} / Código: {$contractor->code}");

        return response()->json($contractor, 201);
    }

    public function updateContractorRating(Request $request, Contractor $contractor)
    {
        $data = $request->validate([
            'rating' => ['required', 'numeric', 'min:0', 'max:5'],
        ]);

        $contractor->update(['rating' => round($data['rating'], 1)]);

        return response()->json([
            'code'   => $contractor->code,
            'rating' => $contractor->rating,
        ]);
    }

    public function materials()
    {
        return MaterialCatalog::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'estimatedUnitPrice' => $item->estimated_unit_price,
            ]);
    }

    public function auditLogs(Request $request)
    {
        $perPage = min((int) ($request->get('per_page', 50)), 200);

        return AuditLog::latest('logged_at')
            ->paginate($perPage)
            ->through(fn ($log) => [
                'id' => $log->id,
                'projectId' => $log->project_id,
                'projectTitle' => $log->project_title_snapshot,
                'role' => $log->role,
                'userName' => $log->user_name_snapshot,
                'action' => $log->action,
                'timestamp' => optional($log->logged_at)->format('Y-m-d H:i'),
                'details' => $log->details,
            ]);
    }

    public function createSupplierInvitation(Request $request)
    {
        $data = $request->validate([
            'project_id'      => ['required', 'string', 'exists:projects,id'],
            'supplierName'    => ['required', 'string', 'max:180'],
            'supplierCompany' => ['nullable', 'string', 'max:180'],
            'supplierContact' => ['required', 'email', 'max:180'],
        ]);

        $project = Project::find($data['project_id']);

        $newId = Str::uuid()->toString();

        // Invalidar enlaces previos activos para el mismo proyecto + contacto
        SupplierInvitation::where('project_id', $data['project_id'])
            ->where('supplier_contact', $data['supplierContact'])
            ->whereNull('used_at')
            ->whereNull('replaced_by')
            ->update(['replaced_by' => $newId]);

        $invitation = SupplierInvitation::create([
            'id'               => $newId,
            'project_id'       => $data['project_id'],
            'supplier_name'    => $data['supplierName'],
            'supplier_company' => $data['supplierCompany'] ?? null,
            'supplier_contact' => $data['supplierContact'],
            'expires_at'       => now()->addDays(SupplierInvitation::DEFAULT_VALIDITY_DAYS),
        ]);

        return response()->json([
            'token'          => $invitation->id,
            'projectTitle'   => $project->title,
            'supplierName'   => $invitation->supplier_name,
            'supplierContact'=> $invitation->supplier_contact,
            'createdAt'      => $invitation->created_at?->format('Y-m-d H:i'),
            'expiresAt'      => $invitation->expires_at?->format('Y-m-d H:i'),
        ], 201);
    }

    public function getInvitationPublicInfo(Request $request, string $token)
    {
        $invitation = SupplierInvitation::with('project.materials')->find($token);
        if (!$invitation || !$invitation->isValid()) {
            return response()->json(['message' => 'Enlace no valido o expirado.'], 404);
        }

        $project = $invitation->project;

        $this->logPublicAccess($request, 'invitation.view', "Invitación: {$token} / Proveedor: {$invitation->supplier_name}");

        return response()->json([
            'supplierName'    => $invitation->supplier_name,
            'supplierCompany' => $invitation->supplier_company,
            'supplierContact' => $invitation->supplier_contact,
            'project'         => [
                'id'          => $project->id,
                'title'       => $project->title,
                'location'    => $project->location,
                'type'        => $project->type,
                'description' => $project->description,
                'materials'   => $project->materials->map(fn ($m) => [
                    'id'                 => $m->id,
                    'name'               => $m->name,
                    'quantity'           => $m->quantity,
                    'unit'               => $m->unit,
                    'estimatedUnitPrice' => $m->estimated_unit_price,
                ]),
            ],
        ]);
    }

    public function storeSupplierMaterialProposal(Request $request, string $token)
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

        $this->logPublicAccess($request, 'proposal.submit', "Propuesta: {$proposal->id} / Invitación: {$token} / Proveedor: {$invitation->supplier_name}");

        return response()->json($this->formatProposal($proposal), 201);
    }

    public function supplierMaterialProposals(Request $request)
    {
        $perPage = min((int) ($request->get('per_page', 20)), 100);

        $query = SupplierMaterialProposal::latest('submitted_at');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        return response()->json($query->paginate($perPage)->through(fn ($p) => $this->formatProposal($p)));
    }

    private function formatProposal(SupplierMaterialProposal $p): array
    {
        return [
            'id'                     => $p->id,
            'projectId'              => $p->project_id,
            'projectTitleSnapshot'   => $p->project_title_snapshot,
            'supplierName'           => $p->supplier_name,
            'supplierCompany'        => $p->supplier_company,
            'supplierContact'        => $p->supplier_contact,
            'items'                  => $p->items,
            'generalNotes'           => $p->general_notes,
            'estimatedDays'          => $p->estimated_days,
            'durationUnit'           => $p->duration_unit,
            'advancePercent'         => $p->advance_percent,
            'submittedAt'            => optional($p->submitted_at)->format('Y-m-d H:i'),
        ];
    }

    /**
     * Log public endpoint access for audit trail.
     */
    private function logPublicAccess(Request $request, string $action, ?string $detail = null): void
    {
        Log::info('PUBLIC_ACCESS', [
            'action'    => $action,
            'ip'        => $request->ip(),
            'user_agent'=> $request->userAgent(),
            'detail'    => $detail,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

}
