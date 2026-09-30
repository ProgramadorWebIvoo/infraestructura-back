<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddProjectProposalRequest;
use App\Http\Requests\ApproveInvestmentRequest;
use App\Http\Requests\PayProjectRequest;
use App\Http\Requests\RejectProjectRequest;
use App\Http\Requests\RejectProposalsRequest;
use App\Http\Requests\RenegotiateProposalRequest;
use App\Http\Requests\ResolveReevaluationRequest;
use App\Http\Requests\ResubmitProjectRequest;
use App\Http\Requests\ReviewProjectRequest;
use App\Http\Requests\SendToReevaluationRequest;
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
use App\Models\ProjectRateFreeze;
use App\Services\AiFeatureGate;
use App\Services\DossierEvaluationService;
use App\Services\ClosureReportLinkService;
use App\Models\PaymentOrder;
use App\Services\PaymentOrderService;
use App\Services\PaymentSignatureService;
use App\Services\ProjectStateMachine;
use App\Services\ResidentAssignmentService;
use App\Support\ProjectLocation;
use App\Services\ProposalCurrencyConverter;
use App\Services\ProposalRenegotiationService;
use App\Services\RateFreezeService;
use App\Services\RejectionService;
use App\Services\SupplierProposalImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    /** @deprecated Usar ProjectStateMachine::STATUSES — se mantiene como alias durante la transición. */
    private const STATUSES = ProjectStateMachine::STATUSES;

    /**
     * El frontend mantiene TODA la lista de proyectos en memoria (filtra por
     * status/rol client-side en cada panel) y nunca lee meta/links de
     * paginación — paginar acá los truncaba en silencio: con más de 20
     * proyectos, los más antiguos (p.ej. en VERIFICANDO_FINALIZACION)
     * desaparecían de todos los paneles sin error visible.
     */
    public function index(Request $request)
    {
        $query = Project::visibleTo($request->user())->with(Project::listRelations())->latest('created_date');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return ProjectResource::collection($query->get());
    }

    public function show(Project $project)
    {
        return new ProjectResource($project->load(Project::detailRelations()));
    }

    public function store(StoreProjectRequest $request)
    {
        $data = $request->validated();
        $requesterId = $request->user()->id;

        $project = DB::transaction(function () use ($data, $requesterId) {
            $project = Project::create([
                'id' => Project::nextId(),
                'title' => $data['title'],
                'type' => $data['type'],
                'description' => $data['description'],
                ...ProjectLocation::attributes($data),
                'created_date' => now()->toDateString(),
                'status' => self::STATUSES['CREADO'],
                'estimated_total' => $data['estimatedTotal'] ?? $this->materialsTotal($data['materials']),
                'requested_by_user_id' => $requesterId,
            ]);

            $this->syncMaterials($project, $data['materials']);

            AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra', 'Peticion registrada desde el modulo de infraestructura.');

            return $project;
        });

        return (new ProjectResource($project->load(Project::detailRelations())))->response()->setStatusCode(201);
    }

    /**
     * Auditoría revisa (audita) la petición — no sube documentación
     * propia, solo confirma lo ya adjuntado por Infraestructura. Por eso no
     * recibe blueprintsCount/calculationsAdded del cliente: esos campos ya
     * los mantiene sincronizados ProjectDocumentController::syncProjectCounts()
     * desde que Infraestructura cargó sus archivos.
     */
    public function review(ReviewProjectRequest $request, Project $project, ResidentAssignmentService $residents)
    {
        $data = $request->validated();

        DB::transaction(function () use ($project, $data, $residents) {
            $residents->assignOnReview($project, $data['residentUserId'] ?? null);

            $project->update([
                'status' => self::STATUSES['REVISADO_AUDITORIA'],
                'audit_notes' => $data['notes'] ?? null,
            ]);
        });

        AuditLog::record($project, 'AUDITORIA', 'Revision tecnica de calculos y planos', $data['notes'] ?? null);

        return new ProjectResource($project->load(Project::detailRelations()));
    }

    /**
     * Evaluación IA del expediente — herramienta de Auditoría para
     * apoyar su revisión. Se llama desde el frontend tanto en la primera
     * apertura automática del wizard de revisión como en un reintento
     * manual explícito ("Reevaluar") — es la misma ruta en ambos casos.
     * EN_REEVALUACION_AUDITORIA incluido porque ReviewWizardModal corre este
     * mismo panel (mode="reevaluation") cuando Procura devuelve un
     * expediente — ver ReevaluationSection.tsx.
     */
    public function evaluateDossier(Project $project, DossierEvaluationService $service)
    {
        ProjectStateMachine::assertStatusIn(
            $project,
            [self::STATUSES['CREADO'], self::STATUSES['RECHAZADO_AUDITORIA'], self::STATUSES['EN_REEVALUACION_AUDITORIA']],
            'Solo se puede evaluar el expediente mientras está pendiente de revisión por Auditoría.'
        );

        abort_unless(
            AiFeatureGate::isEnabled('AUDITORIA', 'ia.auditoria.evaluacion_expediente'),
            403,
            'La evaluación IA está deshabilitada para Auditoría. Contacte a un SUPERADMIN.'
        );

        // DossierEvaluationService::evaluate() es best-effort y nunca lanza
        // (un proveedor de IA caído no debe romper la revisión manual del
        // auditor), así que acá no aplica abort_if/abort_unless como en el
        // resto del controller — se traduce el fallo a un 503 explícito.
        if (!$service->evaluate($project)) {
            return response()->json([
                'success' => false,
                'error' => 'La evaluación no está disponible. Verifique que haya al menos un proveedor de IA configurado en /config-ia.',
            ], 503);
        }

        return new ProjectResource($project->fresh()->load(Project::detailRelations()));
    }

    /**
     * Rechaza la petición inicial (antes de llegar a revisión de planos/cálculos,
     * que es un flujo separado — ver RevisedDocumentsSection). No confundir con
     * rejectProposals(), que rechaza el cuadro comparativo de Procura.
     */
    public function rejectProject(RejectProjectRequest $request, Project $project)
    {
        $project = RejectionService::reject(
            $project,
            self::STATUSES['CREADO'],
            self::STATUSES['RECHAZADO_AUDITORIA'],
            'AUDITORIA',
            'Rechazo de petición de obra',
            $request->validated(),
            function (Project $project, array $payload) {}
        );

        return new ProjectResource($project);
    }

    /**
     * Infraestructura edita y reenvía una petición rechazada — mismo Project.id,
     * no crea uno nuevo. Reemplaza materiales (borrar+recrear, igual que store())
     * y vuelve el status a CREADO para que Auditoría la reevalúe.
     */
    public function resubmitProject(ResubmitProjectRequest $request, Project $project)
    {
        ProjectStateMachine::assertStatus($project, self::STATUSES['RECHAZADO_AUDITORIA'], 'Solo se puede reenviar una petición rechazada.');

        $data = $request->validated();

        $project = DB::transaction(function () use ($data, $project) {
            $project->update([
                'title' => $data['title'],
                'description' => $data['description'],
                ...ProjectLocation::attributes($data),
                'status' => self::STATUSES['CREADO'],
                'estimated_total' => $data['estimatedTotal'] ?? $this->materialsTotal($data['materials']),
                // El análisis de IA previo describe un expediente que ya no
                // existe en esta forma (materiales reemplazados abajo) — se
                // invalida en vez de dejarlo visible como si fuera vigente.
                // DossierEvaluationPanel detecta la ausencia y se re-evalúa
                // solo al abrir el wizard de revisión.
                'dossier_ai_score' => null,
                'dossier_ai_summary' => null,
                'dossier_ai_alerts' => null,
                'dossier_ai_recommendation' => null,
                'dossier_ai_suggested_amount' => null,
                'dossier_ai_completeness_factors' => null,
                'dossier_ai_provider' => null,
                'dossier_ai_evaluated_at' => null,
            ]);

            $project->materials()->delete();
            $this->syncMaterials($project, $data['materials']);

            AuditLog::record($project, 'INFRAESTRUCTURA', 'Reenvío de petición corregida', 'Petición editada y reenviada a Auditoría tras rechazo.');

            return $project;
        });

        return new ProjectResource($project->load(Project::detailRelations()));
    }

    /**
     * Procura devuelve a Auditoría, con motivo obligatorio, un
     * expediente que acaba de recibir (REVISADO_AUDITORIA) para que lo
     * reevalúe antes de autorizar inversión — distinto de rejectProject()
     * (Auditoría rechaza hacia Infraestructura) y de rejectProposals()
     * (Procura rechaza el cuadro comparativo ya en licitación).
     */
    public function sendToReevaluation(SendToReevaluationRequest $request, Project $project)
    {
        $project = RejectionService::reject(
            $project,
            self::STATUSES['REVISADO_AUDITORIA'],
            self::STATUSES['EN_REEVALUACION_AUDITORIA'],
            'PROCURA',
            'Solicitud de reevaluación a Auditoría',
            $request->validated(),
            function (Project $project, array $payload) {}
        );

        return new ProjectResource($project);
    }

    /**
     * Auditoría resuelve la reevaluación solicitada por Procura y
     * reenvía el expediente (mismo Project.id) de vuelta a REVISADO_AUDITORIA
     * para que Procura lo revise nuevamente — no pasa por CREADO porque la
     * cubicación y planos ya fueron aprobados, solo se corrige lo señalado.
     */
    public function resolveReevaluation(ResolveReevaluationRequest $request, Project $project)
    {
        ProjectStateMachine::assertStatus($project, self::STATUSES['EN_REEVALUACION_AUDITORIA'], 'Solo se puede resolver un expediente en reevaluación.');

        $data = $request->validated();

        $project->update(['status' => self::STATUSES['REVISADO_AUDITORIA']]);

        AuditLog::record($project, 'AUDITORIA', 'Reevaluación resuelta, reenviado a Procura', $data['notes'] ?? null);

        return new ProjectResource($project->load(Project::detailRelations()));
    }

    /** Crea los ProjectMaterial de un proyecto a partir del array validado — usado por store() y resubmitProject(). */
    private function syncMaterials(Project $project, array $materials): void
    {
        foreach ($materials as $index => $item) {
            $project->materials()->create([
                'id' => $item['id'] ?? $project->id . '-MAT-' . ($index + 1),
                'material_catalog_id' => $item['materialCatalogId'] ?? null,
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'estimated_unit_price' => $item['estimatedUnitPrice'],
                'condition' => $item['condition'],
                'warranty_value' => $item['warrantyValue'] ?? null,
                'warranty_unit' => $item['warrantyUnit'] ?? null,
                'brand' => $item['brand'] ?? null,
                'model' => $item['model'] ?? null,
                'specifications' => $item['specifications'] ?? null,
                'observations' => $item['observations'] ?? null,
            ]);
        }
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

        return new ProjectResource($project->load(Project::detailRelations()));
    }

    public function addProposal(AddProjectProposalRequest $request, Project $project, ProposalCurrencyConverter $currencyConverter)
    {
        $data = $request->validated();

        $contractor = Contractor::findOrFail($data['contractorCode']);

        $proposal = $project->proposals()->create([
            'id' => ProjectProposal::nextId(),
            'contractor_code' => $contractor->code,
            'contractor_name_snapshot' => $contractor->name,
            ...$currencyConverter->columnsFor($data),
            'material_items' => \App\Support\ProposalMaterialItemsNormalizer::withCatalogIds($project, $data['materialItems'] ?? null),
            'delivery_weeks' => $data['deliveryWeeks'],
            'duration_value' => $data['durationValue'] ?? null,
            'duration_unit' => $data['durationUnit'] ?? null,
            'negotiated_advance_percent' => $data['negotiatedAdvancePercent'],
            'description' => $data['description'],
            'origen' => $data['origen'],
            'fecha_oferta' => $data['fechaOferta'],
            'created_by' => auth()->id(),
            'motivo_anticipo_excedido' => $data['motivoAnticipoExcedido'] ?? null,
        ]);

        $auditDetails = "Oferta {$proposal->id} cargada por {$contractor->name}.";
        if ($proposal->fx_rate_to_base !== null) {
            $auditDetails .= " Cotizada en {$proposal->quote_currency} (tasa a {$proposal->base_currency_at_import}: {$proposal->fx_rate_to_base}).";
        }
        if ($proposal->motivo_anticipo_excedido) {
            $auditDetails .= " Motivo exceso de anticipo: {$proposal->motivo_anticipo_excedido}";
        }
        AuditLog::record($project, 'ANALISTA', 'Carga de propuesta', $auditDetails);
        \App\Support\CacheVersion::bump('contractor_history:' . $contractor->code);
        $this->invalidateBidEvaluationAiCache($project);

        return new ProjectResource($project->load(Project::detailRelations()));
    }

    /**
     * Renegocia una propuesta ya cargada: reemplaza sus términos por unos
     * nuevos SIN borrar ni sobrescribir el registro original — precio
     * anterior, precio nuevo, diferencia y motivo quedan permanentemente
     * auditables (base del futuro análisis inflacionario de productos). El
     * precio anterior se toma del total_cost de la propuesta original, nunca
     * del cliente, para que no pueda quedar desincronizado por error de
     * tipeo. La propuesta original se marca replaced_by_id y desaparece del
     * cuadro comparativo activo (ver scope en ProjectResource) pero sigue
     * existiendo en la base de datos.
     */
    public function renegotiateProposal(RenegotiateProposalRequest $request, Project $project, ProjectProposal $proposal, ProposalRenegotiationService $renegotiationService)
    {
        abort_unless($proposal->project_id === $project->id, 422, 'La propuesta no pertenece al proyecto.');
        abort_if($proposal->replaced_by_id !== null, 422, 'Esta propuesta ya fue renegociada anteriormente.');
        abort_if($project->selected_proposal_id === $proposal->id, 422, 'No se puede renegociar una propuesta ya adjudicada.');

        $data = $request->validated();
        $precioAnterior = (float) $proposal->total_cost;

        $renegotiated = $renegotiationService->apply($project, $proposal, $data + ['createdBy' => auth()->id()]);
        // Ya convertido a la moneda base por el servicio (el cliente envía los
        // montos en quoteCurrency): comparar contra el precio anterior en la misma unidad.
        $precioNuevo = (float) $renegotiated->total_cost;

        $auditDetails = "Propuesta {$proposal->id} ({$proposal->contractor_name_snapshot}) renegociada como {$renegotiated->id}. " .
            "Precio anterior: {$precioAnterior}. Precio nuevo: {$precioNuevo}. Diferencia: " . ($precioNuevo - $precioAnterior) . ". " .
            "Motivo: {$renegotiated->motivo}";
        if ($renegotiated->motivo_anticipo_excedido) {
            $auditDetails .= " Motivo exceso de anticipo: {$renegotiated->motivo_anticipo_excedido}";
        }
        AuditLog::record($project, 'ANALISTA', 'Renegociación de propuesta', $auditDetails);
        $this->invalidateBidEvaluationAiCache($project);

        return new ProjectResource($project->load(Project::detailRelations()));
    }

    public function submitComparative(Project $project)
    {
        abort_if($project->proposals()->count() === 0, 422, 'El proyecto no tiene propuestas cargadas.');

        $project->update(['status' => self::STATUSES['COMPARATIVA_ENVIADA']]);
        AuditLog::record($project, 'ANALISTA', 'Carga de cuadro comparativo', 'Comparativa enviada a Procura para adjudicacion.');

        return new ProjectResource($project->load(Project::detailRelations()));
    }

    public function importSupplierProposals(Project $project, SupplierProposalImportService $importService)
    {
        $result = $importService->import($project);
        $imported = $result['imported'];
        $skipped = $result['skipped'];
        $errors = $result['errors'];

        if ($imported === 0 && $skipped === 0 && empty($errors)) {
            return response()->json([
                'message' => 'No hay propuestas de materiales recibidas de proveedores para este proyecto.',
                'imported' => 0,
                'skipped' => 0,
                'errors' => [],
                'project' => new ProjectResource($project->load(Project::detailRelations())),
            ]);
        }

        AuditLog::record($project, 'ANALISTA', 'Importación automática de propuestas de proveedores',
            "{$imported} propuesta(s) importada(s) desde el portal de proveedores" . ($skipped > 0 ? ", {$skipped} omitida(s)." : "."));

        return response()->json([
            'message' => "Se importaron {$imported} propuesta(s)" . ($skipped > 0 ? " ({$skipped} omitida(s))." : "."),
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'project' => new ProjectResource($project->load(Project::detailRelations())),
        ]);
    }

    public function removeProposal(Project $project, ProjectProposal $proposal)
    {
        abort_unless($proposal->project_id === $project->id, 422, 'La propuesta no pertenece al proyecto.');
        abort_if($project->selected_proposal_id === $proposal->id, 422, 'No se puede eliminar una propuesta adjudicada.');
        abort_if($proposal->replaced_by_id !== null, 422, 'No se puede eliminar una propuesta renegociada: forma parte del historial auditable.');

        $proposal->delete();
        AuditLog::record($project, 'ANALISTA', 'Eliminacion de propuesta', "Propuesta {$proposal->id} retirada del cuadro comparativo.");
        $this->invalidateBidEvaluationAiCache($project);
        \App\Support\CacheVersion::bump('contractor_history:' . $proposal->contractor_code);

        return new ProjectResource($project->load(Project::detailRelations()));
    }

    /**
     * Cualquier cambio al conjunto de propuestas (carga, renegociación,
     * eliminación) vuelve obsoleto un análisis IA cacheado anteriormente —
     * ver AIEvaluationController::cacheEvaluation().
     */
    private function invalidateBidEvaluationAiCache(Project $project): void
    {
        $project->update([
            'bid_evaluation_ai_winner_code' => null,
            'bid_evaluation_ai_winner_name' => null,
            'bid_evaluation_ai_confidence_score' => null,
            'bid_evaluation_ai_summary' => null,
            'bid_evaluation_ai_strengths' => null,
            'bid_evaluation_ai_weaknesses' => null,
            'bid_evaluation_ai_risk_factors' => null,
            'bid_evaluation_ai_recommendation' => null,
            'bid_evaluation_ai_provider' => null,
            'bid_evaluation_ai_evaluated_at' => null,
        ]);
    }

    public function rejectProposals(RejectProposalsRequest $request, Project $project)
    {
        $project = RejectionService::reject(
            $project,
            self::STATUSES['COMPARATIVA_ENVIADA'],
            self::STATUSES['CONFIRMADO_PROCURA'],
            'PROCURA',
            'Rechazo de cuadro comparativo',
            $request->validated(),
            function (Project $project, array $payload) {
                $affectedContractorCodes = $project->proposals()->pluck('contractor_code')->unique();
                $project->proposals()->delete();
                $project->selected_contractor_code = null;
                $project->selected_proposal_id = null;

                foreach ($affectedContractorCodes as $code) {
                    \App\Support\CacheVersion::bump('contractor_history:' . $code);
                }
            }
        );

        return new ProjectResource($project);
    }

    public function selectContractor(SelectContractorRequest $request, Project $project, PaymentOrderService $paymentOrders, PaymentSignatureService $signatures)
    {
        ProjectStateMachine::assertStatus($project, self::STATUSES['COMPARATIVA_ENVIADA'], 'Solo se puede seleccionar un contratista con el cuadro comparativo enviado (COMPARATIVA_ENVIADA).');

        $data = $request->validated();

        $selectedProposal = $project->proposals()->whereKey($data['proposalId'])->first();
        abort_unless($selectedProposal, 422, 'La propuesta no pertenece al proyecto.');

        DB::transaction(function () use ($project, $data, $paymentOrders, $signatures, $request) {
            $project->update([
                'status' => self::STATUSES['PENDIENTE_PRESIDENCIA'],
                'selected_contractor_code' => $data['contractorCode'],
                'selected_proposal_id' => $data['proposalId'],
            ]);

            // La orden de anticipo nace aquí (D10): Presidencia debe firmar
            // una orden ya existente al aprobar la adjudicación.
            $order = $paymentOrders->generate($project->fresh(), PaymentOrder::TYPE_ADVANCE);

            // Solo mejor esfuerzo (nunca bloquea): esta es la PRIMERA acción
            // del circuito, así que "el próximo paso obligatorio" puede caer
            // más adelante en la cadena (ej. un paso opcional antes) sin que
            // eso signifique que selectContractor deba esperarlo — el gate
            // real está en award-approval y pay(), donde si aplica de verdad.
            $signatures->trySign($order, auth()->user(), $request);
        });

        AuditLog::record($project, 'PROCURA', 'Seleccion de contratista pendiente de Presidencia', "Contratista {$data['contractorCode']} seleccionado; pendiente de aprobación de Presidencia.");

        return new ProjectResource($project->fresh()->load(Project::detailRelations()));
    }

    public function pay(PayProjectRequest $request, Project $project, RateFreezeService $rateFreezeService, ClosureReportLinkService $closureLinks, PaymentOrderService $paymentOrders, PaymentSignatureService $signatures)
    {
        $data = $request->validated();

        // El anticipo solo procede recién adjudicado el contratista; el pago
        // final solo tras verificar calidad — sin esto, FINANZAS podía pagar
        // un proyecto en cualquier estado, incluyendo reabrir uno ya cerrado.
        if ($data['paymentType'] === 'ADVANCE') {
            ProjectStateMachine::assertStatus($project, self::STATUSES['CONTRATADO'], 'El anticipo solo se puede liberar con el contratista recién adjudicado (CONTRATADO).');
        } else {
            ProjectStateMachine::assertStatus($project, self::STATUSES['LISTO_PAGO_FINAL'], 'El pago final solo se puede liberar tras la verificación de calidad (LISTO_PAGO_FINAL).');
        }

        // El monto ya no lo decide quien paga: sale de la orden vigente
        // (F4 D10); si el que envía el cliente no coincide, se rechaza. Fuera
        // de la transacción de escritura porque solo lee (regla 2.5).
        $order = $paymentOrders->assertReadyToPay($project, $data['paymentType'], (float) $data['amount']);
        // La cadena debe estar completa salvo, a lo sumo, el último paso —
        // que Finanzas firma dentro de la transacción de este método.
        $signatures->assertCanProceed($order, auth()->user());

        // Todo movimiento contable requiere comprobante (Plan Maestro, Finanzas):
        // el cliente ya lo sube antes, pero la regla se garantiza aquí también.
        $proofType = $data['paymentType'] === 'ADVANCE' ? 'COMPROBANTE_ANTICIPO' : 'COMPROBANTE_FINIQUITO';
        $proof = $project->documents()->where('document_type', $proofType)->latest('id')->first();
        if (!$proof) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'proof' => 'Todo pago requiere un comprobante adjunto antes de confirmarse.',
            ]);
        }

        DB::transaction(function () use ($project, $data, $rateFreezeService, $proof, $order, $signatures, $request) {
            // Firma el paso de Finanzas — ya se validó arriba que le corresponde.
            $signatures->signOrSkip($order, auth()->user(), $request);

            ProjectPayment::updateOrCreate(
                ['project_id' => $project->id, 'payment_type' => $data['paymentType']],
                [
                    'proposal_id' => $project->selected_proposal_id,
                    'payment_order_id' => $order->id,
                    // Siempre en moneda base (lo asumen los agregados); la obligación en
                    // moneda de cotización vive en la orden (amount/currency).
                    'amount' => $order->amount_base,
                    'paid_date' => $data['paidDate'] ?? now()->toDateString(),
                    'notes' => $data['notes'] ?? null,
                    'bank' => $data['bank'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'comprobante_document_id' => $proof->id,
                ]
            );

            $order->update(['status' => \App\Models\PaymentOrder::STATUS_PAGADA]);

            $project->update(['status' => $data['paymentType'] === 'ADVANCE' ? self::STATUSES['EN_EJECUCION'] : self::STATUSES['COMPLETADO_PAGADO']]);

            // Congela la tasa BCV vigente para el monto de este pago (si el
            // trigger está habilitado en CONFIG APP) — ver RateFreezeService.
            $rateFreezeService->freezeForTrigger(
                $project,
                $data['paymentType'] === 'ADVANCE' ? ProjectRateFreeze::TRIGGER_PAGO_ANTICIPO : ProjectRateFreeze::TRIGGER_PAGO_FINIQUITO,
                (float) $order->amount_base
            );
        });

        AuditLog::record($project, 'FINANZAS', $data['paymentType'] === 'ADVANCE' ? 'Liberacion de anticipo' : 'Liberacion total de fondos', $data['notes'] ?? null);

        if ($data['paymentType'] === 'ADVANCE') {
            $closureLinks->open($project);
        }

        return new ProjectResource($project->load(Project::detailRelations()));
    }

    private function materialsTotal(array $materials): float
    {
        return collect($materials)->sum(fn ($item) => $item['quantity'] * $item['estimatedUnitPrice']);
    }

}
