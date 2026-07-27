<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectPayment;
use App\Models\ProjectProposal;
use App\Models\SupplierMaterialProposal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    private const STATUSES = [
        'CREADO'                => 'CREADO',
        'REVISADO_CIERRE'       => 'REVISADO_CIERRE',
        'CONFIRMADO_PROCURA'    => 'CONFIRMADO_PROCURA',
        'COMPARATIVA_ENVIADA'   => 'COMPARATIVA_ENVIADA',
        'CONTRATADO'            => 'CONTRATADO',
        'EN_EJECUCION'          => 'EN_EJECUCION',
        'VERIFICANDO_FINALIZACION' => 'VERIFICANDO_FINALIZACION',
        'LISTO_PAGO_FINAL'      => 'LISTO_PAGO_FINAL',
        'COMPLETADO_PAGADO'     => 'COMPLETADO_PAGADO',
    ];

    public function index(Request $request)
    {
        $perPage = min((int) ($request->get('per_page', 20)), 100);

        $query = Project::with(['materials', 'proposals', 'payments', 'documents'])->latest('created_date');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return ProjectResource::collection($query->paginate($perPage));
    }

    public function show(Project $project)
    {
        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:220'],
            'type' => ['required', Rule::in(['INFRAESTRUCTURA', 'MANTENIMIENTO'])],
            'description' => ['required', 'string'],
            'location' => ['required', 'string', 'max:180'],
            'materials' => ['required', 'array', 'min:1'],
            'materials.*.id' => ['nullable', 'string', 'max:40'],
            'materials.*.materialCatalogId' => ['nullable', 'integer', 'exists:material_catalog,id'],
            'materials.*.name' => ['required', 'string', 'max:180'],
            'materials.*.quantity' => ['required', 'numeric', 'min:0'],
            'materials.*.unit' => ['required', 'string', 'max:80'],
            'materials.*.estimatedUnitPrice' => ['required', 'numeric', 'min:0'],
            'estimatedTotal' => ['nullable', 'numeric', 'min:0'],
        ]);

        $project = DB::transaction(function () use ($data) {
            $project = Project::create([
                'id' => $this->nextProjectId(),
                'title' => $data['title'],
                'type' => $data['type'],
                'description' => $data['description'],
                'location' => $data['location'],
                'created_date' => now()->toDateString(),
                'status' => self::STATUSES['CREADO'],
                'estimated_total' => $data['estimatedTotal'] ?? $this->materialsTotal($data['materials']),
            ]);

            foreach ($data['materials'] as $index => $item) {
                $project->materials()->create([
                    'id' => $item['id'] ?? $project->id . '-MAT-' . ($index + 1),
                    'material_catalog_id' => $item['materialCatalogId'] ?? null,
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'estimated_unit_price' => $item['estimatedUnitPrice'],
                ]);
            }

            $this->log($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra', 'Peticion registrada desde el modulo de infraestructura.');

            return $project;
        });

        return (new ProjectResource($project->load(['materials', 'proposals', 'payments'])))->response()->setStatusCode(201);
    }

    public function review(Request $request, Project $project)
    {
        $data = $request->validate([
            'notes' => ['required', 'string'],
            'blueprintsCount' => ['required', 'integer', 'min:0'],
            'calculationsAdded' => ['required', 'boolean'],
        ]);

        $project->update([
            'status' => self::STATUSES['REVISADO_CIERRE'],
            'cierre_obra_notes' => $data['notes'],
            'blueprints_count' => $data['blueprintsCount'],
            'calculations_added' => $data['calculationsAdded'],
        ]);

        $this->log($project, 'CIERRE_DE_OBRA', 'Revision tecnica de calculos y planos', $data['notes']);

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function approveInvestment(Request $request, Project $project)
    {
        $data = $request->validate([
            'notes' => ['required', 'string'],
            'approvedInvestmentAmount' => ['required', 'numeric', 'min:0'],
        ]);

        $project->update([
            'status' => self::STATUSES['CONFIRMADO_PROCURA'],
            'procura_review_notes' => $data['notes'],
            'approved_investment_amount' => $data['approvedInvestmentAmount'],
        ]);

        $this->log($project, 'PROCURA', 'Confirmacion de presupuesto y envio a licitacion', $data['notes']);

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function addProposal(Request $request, Project $project)
    {
        $data = $request->validate([
            'contractorCode' => ['required', 'exists:contractors,code'],
            'materialCost' => ['required', 'numeric', 'min:0'],
            'laborCost' => ['required', 'numeric', 'min:0'],
            'totalCost' => ['required', 'numeric', 'min:0'],
            'deliveryWeeks' => ['required', 'integer', 'min:0'],
            'negotiatedAdvancePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['required', 'string'],
        ]);

        $contractor = Contractor::findOrFail($data['contractorCode']);

        $proposal = $project->proposals()->create([
            'id' => 'PROP-' . now()->format('Hisv') . '-' . Str::random(4),
            'contractor_code' => $contractor->code,
            'contractor_name_snapshot' => $contractor->name,
            'material_cost' => $data['materialCost'],
            'labor_cost' => $data['laborCost'],
            'total_cost' => $data['totalCost'],
            'delivery_weeks' => $data['deliveryWeeks'],
            'negotiated_advance_percent' => $data['negotiatedAdvancePercent'],
            'description' => $data['description'],
        ]);

        $this->log($project, 'ANALISTA', 'Carga de propuesta', "Oferta {$proposal->id} cargada por {$contractor->name}.");

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function submitComparative(Project $project)
    {
        abort_if($project->proposals()->count() === 0, 422, 'El proyecto no tiene propuestas cargadas.');

        $project->update(['status' => self::STATUSES['COMPARATIVA_ENVIADA']]);
        $this->log($project, 'ANALISTA', 'Carga de cuadro comparativo', 'Comparativa enviada a Procura para adjudicacion.');

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function importSupplierProposals(Project $project)
    {
        $supplierProposals = SupplierMaterialProposal::where('project_id', $project->id)->get();

        if ($supplierProposals->isEmpty()) {
            return response()->json([
                'message' => 'No hay propuestas de materiales recibidas de proveedores para este proyecto.',
                'imported' => 0,
                'skipped' => 0,
                'errors' => [],
                'project' => new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents'])),
            ]);
        }

        $existingCodes = $project->proposals()->pluck('contractor_code')->toArray();
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($supplierProposals as $supplierProposal) {
            // Find matching contractor by email or name
            $contractor = Contractor::where('contact', $supplierProposal->supplier_contact)
                ->orWhere('name', $supplierProposal->supplier_name)
                ->first();

            if (!$contractor) {
                $skipped++;
                $errors[] = "No se encontró contratista registrado para: {$supplierProposal->supplier_name} ({$supplierProposal->supplier_contact})";
                continue;
            }

            // Skip if already has a proposal from this contractor
            if (in_array($contractor->code, $existingCodes)) {
                $skipped++;
                continue;
            }

            // Calculate values from supplier material proposal
            $materialCost = collect($supplierProposal->items)->sum('totalPrice');
            $laborCost = 0;
            $totalCost = $materialCost + $laborCost;

            // Convert estimated duration to weeks
            $deliveryWeeks = match ($supplierProposal->duration_unit) {
                'dias' => max(1, (int) ceil(($supplierProposal->estimated_days ?? 1) / 7)),
                'meses' => ($supplierProposal->estimated_days ?? 1) * 4,
                default => $supplierProposal->estimated_days ?? 4, // 'semanas' or null
            };

            $description = $supplierProposal->general_notes
                ?? "Propuesta de materiales de {$supplierProposal->supplier_name}. Presupuesto total de materiales: \$" . number_format($totalCost, 2);

            $project->proposals()->create([
                'id' => 'PROP-' . now()->format('Hisv') . '-' . Str::random(4),
                'contractor_code' => $contractor->code,
                'contractor_name_snapshot' => $contractor->name,
                'material_cost' => $materialCost,
                'labor_cost' => $laborCost,
                'total_cost' => $totalCost,
                'delivery_weeks' => $deliveryWeeks,
                'negotiated_advance_percent' => 30,
                'description' => $description,
            ]);

            $existingCodes[] = $contractor->code;
            $imported++;
        }

        $this->log($project, 'ANALISTA', 'Importación automática de propuestas de proveedores',
            "{$imported} propuesta(s) importada(s) desde el portal de proveedores" . ($skipped > 0 ? ", {$skipped} omitida(s)." : "."));

        return response()->json([
            'message' => "Se importaron {$imported} propuesta(s)" . ($skipped > 0 ? " ({$skipped} omitida(s))." : "."),
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'project' => new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents'])),
        ]);
    }

    public function removeProposal(Project $project, ProjectProposal $proposal)
    {
        abort_unless($proposal->project_id === $project->id, 422, 'La propuesta no pertenece al proyecto.');
        abort_if($project->selected_proposal_id === $proposal->id, 422, 'No se puede eliminar una propuesta adjudicada.');

        $proposal->delete();
        $this->log($project, 'ANALISTA', 'Eliminacion de propuesta', "Propuesta {$proposal->id} retirada del cuadro comparativo.");

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function rejectProposals(Request $request, Project $project)
    {
        abort_unless($project->status === self::STATUSES['COMPARATIVA_ENVIADA'], 422, 'Solo se puede rechazar en estado COMPARATIVA_ENVIADA.');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($project, $data) {
            $project->proposals()->delete();

            $project->update([
                'status'                  => self::STATUSES['CONFIRMADO_PROCURA'],
                'selected_contractor_code' => null,
                'selected_proposal_id'    => null,
            ]);

            $this->log($project, 'PROCURA', 'Rechazo de cuadro comparativo', $data['reason']);
        });

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function selectContractor(Request $request, Project $project)
    {
        abort_unless($project->status === self::STATUSES['COMPARATIVA_ENVIADA'], 422, 'Solo se puede adjudicar un contratista con el cuadro comparativo enviado (COMPARATIVA_ENVIADA).');

        $data = $request->validate([
            'contractorCode' => ['required', 'exists:contractors,code'],
            'proposalId' => ['required', 'exists:project_proposals,id'],
        ]);

        abort_unless($project->proposals()->whereKey($data['proposalId'])->exists(), 422, 'La propuesta no pertenece al proyecto.');

        $project->update([
            'status' => self::STATUSES['CONTRATADO'],
            'selected_contractor_code' => $data['contractorCode'],
            'selected_proposal_id' => $data['proposalId'],
        ]);

        $this->log($project, 'PROCURA', 'Confirmacion de contratacion', "Contratista {$data['contractorCode']} adjudicado.");

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function pay(Request $request, Project $project)
    {
        $data = $request->validate([
            'paymentType' => ['required', Rule::in(['ADVANCE', 'FINAL'])],
            'amount' => ['required', 'numeric', 'min:0'],
            'paidDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        // El anticipo solo procede recién adjudicado el contratista; el pago
        // final solo tras verificar calidad — sin esto, FINANZAS podía pagar
        // un proyecto en cualquier estado, incluyendo reabrir uno ya cerrado.
        if ($data['paymentType'] === 'ADVANCE') {
            abort_unless($project->status === self::STATUSES['CONTRATADO'], 422, 'El anticipo solo se puede liberar con el contratista recién adjudicado (CONTRATADO).');
        } else {
            abort_unless($project->status === self::STATUSES['LISTO_PAGO_FINAL'], 422, 'El pago final solo se puede liberar tras la verificación de calidad (LISTO_PAGO_FINAL).');
        }

        ProjectPayment::updateOrCreate(
            ['project_id' => $project->id, 'payment_type' => $data['paymentType']],
            [
                'proposal_id' => $project->selected_proposal_id,
                'amount' => $data['amount'],
                'paid_date' => $data['paidDate'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]
        );

        $project->update(['status' => $data['paymentType'] === 'ADVANCE' ? self::STATUSES['EN_EJECUCION'] : self::STATUSES['COMPLETADO_PAGADO']]);
        $this->log($project, 'FINANZAS', $data['paymentType'] === 'ADVANCE' ? 'Liberacion de anticipo' : 'Liberacion total de fondos', $data['notes'] ?? null);

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function reportFinished(Project $project)
    {
        abort_unless($project->status === self::STATUSES['EN_EJECUCION'], 422, 'Solo se puede reportar como finalizada una obra en ejecución (EN_EJECUCION).');

        $project->update(['status' => self::STATUSES['VERIFICANDO_FINALIZACION']]);
        $this->log($project, 'SISTEMA', 'Reporte de obra finalizada', 'La obra fue marcada como finalizada y pendiente de certificacion.');

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function verifyCompletion(Request $request, Project $project)
    {
        abort_unless($project->status === self::STATUSES['VERIFICANDO_FINALIZACION'], 422, 'Solo se puede verificar la finalización de una obra reportada como terminada (VERIFICANDO_FINALIZACION).');

        $data = $request->validate([
            'qualityVerified' => ['required', 'boolean'],
            'completionVerifiedDate' => ['nullable', 'date'],
            'details' => ['nullable', 'string'],
        ]);

        $project->update([
            'status' => $data['qualityVerified'] ? self::STATUSES['LISTO_PAGO_FINAL'] : self::STATUSES['EN_EJECUCION'],
            'quality_verified' => $data['qualityVerified'],
            'completion_verified_date' => $data['completionVerifiedDate'] ?? now()->toDateString(),
        ]);

        $this->log($project, 'CIERRE_DE_OBRA', 'Verificacion de finalizacion y calidad de obra', $data['details'] ?? null);

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    private function nextProjectId(): string
    {
        $last = Project::query()->select('id')
            ->where('id', 'like', 'PRJ-%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $number = $last ? ((int) substr($last->id, 4)) + 1 : 1;

        return 'PRJ-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    private function materialsTotal(array $materials): float
    {
        return collect($materials)->sum(fn ($item) => $item['quantity'] * $item['estimatedUnitPrice']);
    }

    private function log(Project $project, string $role, string $action, ?string $details): void
    {
        $user = auth()->user();
        AuditLog::create([
            'id' => 'LOG-' . now()->format('YmdHisv') . '-' . Str::random(4),
            'project_id' => $project->id,
            'project_title_snapshot' => $project->title,
            'role' => $role,
            'user_id' => $user?->id,
            'user_name_snapshot' => $user?->name,
            'action' => $action,
            'logged_at' => now(),
            'details' => $details,
        ]);
    }
}
