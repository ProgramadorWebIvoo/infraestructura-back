<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddProjectProposalRequest;
use App\Http\Requests\ApproveInvestmentRequest;
use App\Http\Requests\PayProjectRequest;
use App\Http\Requests\RejectProposalsRequest;
use App\Http\Requests\ReviewProjectRequest;
use App\Http\Requests\SelectContractorRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\VerifyCompletionRequest;
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

    public function store(StoreProjectRequest $request)
    {
        $data = $request->validated();

        $project = DB::transaction(function () use ($data) {
            $project = Project::create([
                'id' => Project::nextId(),
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

            AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra', 'Peticion registrada desde el modulo de infraestructura.');

            return $project;
        });

        return (new ProjectResource($project->load(['materials', 'proposals', 'payments'])))->response()->setStatusCode(201);
    }

    public function review(ReviewProjectRequest $request, Project $project)
    {
        $data = $request->validated();

        $project->update([
            'status' => self::STATUSES['REVISADO_CIERRE'],
            'cierre_obra_notes' => $data['notes'],
            'blueprints_count' => $data['blueprintsCount'],
            'calculations_added' => $data['calculationsAdded'],
        ]);

        AuditLog::record($project, 'CIERRE_DE_OBRA', 'Revision tecnica de calculos y planos', $data['notes']);

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function approveInvestment(ApproveInvestmentRequest $request, Project $project)
    {
        $data = $request->validated();

        $project->update([
            'status' => self::STATUSES['CONFIRMADO_PROCURA'],
            'procura_review_notes' => $data['notes'],
            'approved_investment_amount' => $data['approvedInvestmentAmount'],
        ]);

        AuditLog::record($project, 'PROCURA', 'Confirmacion de presupuesto y envio a licitacion', $data['notes']);

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function addProposal(AddProjectProposalRequest $request, Project $project)
    {
        $data = $request->validated();

        $contractor = Contractor::findOrFail($data['contractorCode']);

        $proposal = $project->proposals()->create([
            'id' => ProjectProposal::nextId(),
            'contractor_code' => $contractor->code,
            'contractor_name_snapshot' => $contractor->name,
            'material_cost' => $data['materialCost'],
            'labor_cost' => $data['laborCost'],
            'total_cost' => $data['totalCost'],
            'delivery_weeks' => $data['deliveryWeeks'],
            'negotiated_advance_percent' => $data['negotiatedAdvancePercent'],
            'description' => $data['description'],
        ]);

        AuditLog::record($project, 'ANALISTA', 'Carga de propuesta', "Oferta {$proposal->id} cargada por {$contractor->name}.");

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function submitComparative(Project $project)
    {
        abort_if($project->proposals()->count() === 0, 422, 'El proyecto no tiene propuestas cargadas.');

        $project->update(['status' => self::STATUSES['COMPARATIVA_ENVIADA']]);
        AuditLog::record($project, 'ANALISTA', 'Carga de cuadro comparativo', 'Comparativa enviada a Procura para adjudicacion.');

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

            // Convert estimated duration to weeks. Sin dato del proveedor, se deja en 0
            // (default real de la columna) en vez de inventar un plazo.
            $deliveryWeeks = match ($supplierProposal->duration_unit) {
                'dias' => $supplierProposal->estimated_days !== null
                    ? max(1, (int) ceil($supplierProposal->estimated_days / 7))
                    : 0,
                'meses' => $supplierProposal->estimated_days !== null
                    ? $supplierProposal->estimated_days * 4
                    : 0,
                'semanas' => $supplierProposal->estimated_days ?? 0,
                default => 0, // sin duration_unit => sin dato
            };

            $description = $supplierProposal->general_notes
                ?? "Propuesta de materiales de {$supplierProposal->supplier_name}. Presupuesto total de materiales: \$" . number_format($totalCost, 2);

            $project->proposals()->create([
                'id' => ProjectProposal::nextId(),
                'contractor_code' => $contractor->code,
                'contractor_name_snapshot' => $contractor->name,
                'material_cost' => $materialCost,
                'labor_cost' => $laborCost,
                'total_cost' => $totalCost,
                'delivery_weeks' => $deliveryWeeks,
                'negotiated_advance_percent' => $supplierProposal->advance_percent ?? 0,
                'description' => $description,
            ]);

            $existingCodes[] = $contractor->code;
            $imported++;
        }

        AuditLog::record($project, 'ANALISTA', 'Importación automática de propuestas de proveedores',
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
        AuditLog::record($project, 'ANALISTA', 'Eliminacion de propuesta', "Propuesta {$proposal->id} retirada del cuadro comparativo.");

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function rejectProposals(RejectProposalsRequest $request, Project $project)
    {
        abort_unless($project->status === self::STATUSES['COMPARATIVA_ENVIADA'], 422, 'Solo se puede rechazar en estado COMPARATIVA_ENVIADA.');

        $data = $request->validated();

        DB::transaction(function () use ($project, $data) {
            $project->proposals()->delete();

            $project->update([
                'status'                  => self::STATUSES['CONFIRMADO_PROCURA'],
                'selected_contractor_code' => null,
                'selected_proposal_id'    => null,
            ]);

            AuditLog::record($project, 'PROCURA', 'Rechazo de cuadro comparativo', $data['reason']);
        });

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function selectContractor(SelectContractorRequest $request, Project $project)
    {
        abort_unless($project->status === self::STATUSES['COMPARATIVA_ENVIADA'], 422, 'Solo se puede adjudicar un contratista con el cuadro comparativo enviado (COMPARATIVA_ENVIADA).');

        $data = $request->validated();

        abort_unless($project->proposals()->whereKey($data['proposalId'])->exists(), 422, 'La propuesta no pertenece al proyecto.');

        $project->update([
            'status' => self::STATUSES['CONTRATADO'],
            'selected_contractor_code' => $data['contractorCode'],
            'selected_proposal_id' => $data['proposalId'],
        ]);

        AuditLog::record($project, 'PROCURA', 'Confirmacion de contratacion', "Contratista {$data['contractorCode']} adjudicado.");

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function pay(PayProjectRequest $request, Project $project)
    {
        $data = $request->validated();

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
        AuditLog::record($project, 'FINANZAS', $data['paymentType'] === 'ADVANCE' ? 'Liberacion de anticipo' : 'Liberacion total de fondos', $data['notes'] ?? null);

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function reportFinished(Project $project)
    {
        abort_unless($project->status === self::STATUSES['EN_EJECUCION'], 422, 'Solo se puede reportar como finalizada una obra en ejecución (EN_EJECUCION).');

        $project->update(['status' => self::STATUSES['VERIFICANDO_FINALIZACION']]);
        AuditLog::record($project, 'SISTEMA', 'Reporte de obra finalizada', 'La obra fue marcada como finalizada y pendiente de certificacion.');

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    public function verifyCompletion(VerifyCompletionRequest $request, Project $project)
    {
        abort_unless($project->status === self::STATUSES['VERIFICANDO_FINALIZACION'], 422, 'Solo se puede verificar la finalización de una obra reportada como terminada (VERIFICANDO_FINALIZACION).');

        $data = $request->validated();

        $project->update([
            'status' => $data['qualityVerified'] ? self::STATUSES['LISTO_PAGO_FINAL'] : self::STATUSES['EN_EJECUCION'],
            'quality_verified' => $data['qualityVerified'],
            'completion_verified_date' => $data['completionVerifiedDate'] ?? now()->toDateString(),
        ]);

        AuditLog::record($project, 'CIERRE_DE_OBRA', 'Verificacion de finalizacion y calidad de obra', $data['details'] ?? null);

        return new ProjectResource($project->load(['materials', 'proposals', 'payments', 'documents']));
    }

    private function materialsTotal(array $materials): float
    {
        return collect($materials)->sum(fn ($item) => $item['quantity'] * $item['estimatedUnitPrice']);
    }

}
