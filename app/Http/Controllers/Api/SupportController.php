<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\MaterialCatalog;
use App\Models\Project;
use App\Models\SupplierMaterialProposal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportController extends Controller
{
    public function modules()
    {
        return DB::table('app_modules')->orderBy('id')->get();
    }

    public function contractors()
    {
        return Contractor::orderBy('name')->get(['code', 'name', 'specialty', 'rating', 'contact', 'status']);
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

        $data['code'] ??= $this->nextContractorCode();
        $data['rating'] ??= 4.0;
        $data['registration_source'] = 'PUBLIC_PORTAL';
        $data['status'] = 'PENDING_REVIEW';

        return response()->json(Contractor::create($data), 201);
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

    public function auditLogs()
    {
        return AuditLog::latest('logged_at')->limit(200)->get()->map(fn ($log) => [
            'id' => $log->id,
            'projectId' => $log->project_id,
            'projectTitle' => $log->project_title_snapshot,
            'role' => $log->role,
            'action' => $log->action,
            'timestamp' => optional($log->logged_at)->format('Y-m-d H:i'),
            'details' => $log->details,
        ]);
    }

    public function getProjectPublicInfo(string $projectId)
    {
        $project = Project::with('materials')->find($projectId);
        if (!$project) {
            return response()->json(['message' => 'Obra no encontrada.'], 404);
        }

        return response()->json([
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
        ]);
    }

    public function storeSupplierMaterialProposal(Request $request, string $projectId)
    {
        $project = Project::find($projectId);
        if (!$project) {
            return response()->json(['message' => 'Obra no encontrada.'], 404);
        }

        $data = $request->validate([
            'supplierName'          => ['required', 'string', 'max:180'],
            'supplierCompany'       => ['nullable', 'string', 'max:180'],
            'supplierContact'       => ['required', 'email', 'max:180'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.materialName'  => ['required', 'string', 'max:220'],
            'items.*.quantity'      => ['required', 'numeric', 'min:0'],
            'items.*.unit'          => ['required', 'string', 'max:60'],
            'items.*.unitPrice'     => ['required', 'numeric', 'min:0'],
            'items.*.totalPrice'    => ['required', 'numeric', 'min:0'],
            'items.*.notes'         => ['nullable', 'string', 'max:500'],
            'generalNotes'          => ['nullable', 'string', 'max:1000'],
        ]);

        $proposal = SupplierMaterialProposal::create([
            'id'                     => $this->nextProposalId(),
            'project_id'             => $projectId,
            'project_title_snapshot' => $project->title,
            'supplier_name'          => $data['supplierName'],
            'supplier_company'       => $data['supplierCompany'] ?? null,
            'supplier_contact'       => $data['supplierContact'],
            'items'                  => $data['items'],
            'general_notes'          => $data['generalNotes'] ?? null,
        ]);

        return response()->json($this->formatProposal($proposal), 201);
    }

    public function supplierMaterialProposals(Request $request)
    {
        $query = SupplierMaterialProposal::latest('submitted_at');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        return response()->json($query->get()->map(fn ($p) => $this->formatProposal($p)));
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
            'submittedAt'            => optional($p->submitted_at)->format('Y-m-d H:i'),
        ];
    }

    private function nextContractorCode(): string
    {
        $last = Contractor::query()
            ->where('code', 'like', 'CON-%')
            ->orderByRaw('CAST(SUBSTRING(code, 5) AS UNSIGNED) DESC')
            ->first();

        $number = $last ? ((int) substr($last->code, 4)) + 1 : 301;

        return 'CON-' . $number;
    }

    private function nextProposalId(): string
    {
        $last = SupplierMaterialProposal::query()
            ->where('id', 'like', 'SMP-%')
            ->orderByRaw('CAST(SUBSTRING(id, 5) AS UNSIGNED) DESC')
            ->first();

        $number = $last ? ((int) substr($last->id, 4)) + 1 : 1;

        return 'SMP-' . str_pad($number, 3, '0', STR_PAD_LEFT);
    }
}
