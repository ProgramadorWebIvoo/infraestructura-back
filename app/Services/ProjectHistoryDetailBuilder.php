<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectDocument;
use App\Support\ProjectFigures;

/**
 * Arma la cadena completa de UNA obra para el Histórico de Obras. Solo lee:
 * no modifica ni recalcula nada del flujo operativo.
 */
class ProjectHistoryDetailBuilder
{
    private const DRAWING_TYPES = ['PLANO', 'CALC', 'CORRECCION'];
    private const TIMELINE_LIMIT = 300;

    public function build(Project $project): array
    {
        $project->load([
            'materials.catalogProduct:id,name',
            'proposals' => fn ($q) => $q->withTrashed(),
            'proposals.creator:id,name',
            'proposals.contractor:code,name',
            'payments.comprobante',
            'rateFreezes',
        ]);

        $awardedProposal = $project->proposals->firstWhere('id', $project->selected_proposal_id);
        $executed = (float) $project->payments->sum('amount');
        $figures = ProjectFigures::build(
            $project->estimated_total !== null ? (float) $project->estimated_total : null,
            $project->approved_investment_amount !== null ? (float) $project->approved_investment_amount : null,
            $awardedProposal ? (float) $awardedProposal->total_cost : null,
            $executed,
        );

        $drawings = $this->drawings($project);
        $auditLogs = $project->auditLogs()->orderBy('logged_at')->orderBy('id')->limit(self::TIMELINE_LIMIT)->get();

        return [
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'type' => $project->type,
                'description' => $project->description,
                'location' => $project->location,
                'status' => $project->status,
                'createdDate' => optional($project->created_date)->format('Y-m-d'),
            ],
            'figures' => $figures,
            'stages' => $this->stages($project, $drawings),
            'budget' => $this->budget($project),
            'request' => $this->request($project, $auditLogs->first()),
            'suppliers' => $this->suppliers($project),
            'award' => $this->award($project, $awardedProposal),
            'payments' => $this->payments($project, $figures['awarded']),
            'drawings' => $drawings,
            'closure' => $this->closure($project),
            'timeline' => $auditLogs->map(fn ($log) => [
                'id' => $log->id,
                'at' => optional($log->logged_at)->toIso8601String(),
                'role' => $log->role,
                'user' => $log->user_name_snapshot,
                'action' => $log->action,
                'details' => $log->details,
                'observations' => $log->observations,
            ])->values()->all(),
        ];
    }

    private function stages(Project $project, array $drawings): array
    {
        $paid = $project->payments->pluck('payment_type');
        $closed = $project->status === 'COMPLETADO_PAGADO';
        $reached = fn (string $status) => (ProjectStateMachine::STATUS_ORDER[$project->status] ?? -1) >= ProjectStateMachine::STATUS_ORDER[$status];

        $states = [
            'obra' => true,
            'presupuesto' => $project->materials->isNotEmpty(),
            'solicitud' => $reached('REVISADO_CIERRE'),
            'proveedores' => $project->proposals->isNotEmpty(),
            'adjudicacion' => $project->selected_proposal_id !== null,
            'pagos' => $paid->contains('FINAL'),
            'planos' => count($drawings) > 0,
            'cierre' => $closed,
        ];
        $labels = [
            'obra' => 'Obra', 'presupuesto' => 'Presupuesto', 'solicitud' => 'Solicitud', 'proveedores' => 'Proveedores',
            'adjudicacion' => 'Adjudicación', 'pagos' => 'Pagos', 'planos' => 'Planos', 'cierre' => 'Cierre',
        ];

        return collect($states)->map(fn ($done, $key) => [
            'key' => $key,
            'label' => $labels[$key],
            'state' => $done ? 'done' : (($key === 'pagos' && $paid->isNotEmpty()) ? 'current' : 'pending'),
        ])->values()->all();
    }

    private function budget(Project $project): array
    {
        $lines = $project->materials->map(fn ($m) => [
            'id' => $m->id,
            'name' => $m->name,
            'quantity' => $m->quantity,
            'unit' => $m->unit,
            'estimatedUnitPrice' => $m->estimated_unit_price,
            'estimatedSubtotal' => round((float) $m->quantity * (float) $m->estimated_unit_price, 2),
            'condition' => $m->condition,
            'brand' => $m->brand,
            'catalogProduct' => $m->catalogProduct ? ['id' => $m->catalogProduct->id, 'name' => $m->catalogProduct->name] : null,
        ])->values();

        return ['lines' => $lines->all(), 'linesTotal' => round($lines->sum('estimatedSubtotal'), 2)];
    }

    private function request(Project $project, $firstLog): array
    {
        return [
            'createdDate' => optional($project->created_date)->format('Y-m-d'),
            'createdBy' => $firstLog?->user_name_snapshot,
            'reviewNotes' => $project->cierre_obra_notes,
            'procuraNotes' => $project->procura_review_notes,
            'dossierAiScore' => $project->dossier_ai_score,
        ];
    }

    private function suppliers(Project $project): array
    {
        return $project->proposals->sortBy('created_at')->map(fn ($p) => [
            'id' => $p->id,
            'contractorCode' => $p->contractor_code,
            'contractorName' => $p->contractor_name_snapshot ?? $p->contractor?->name,
            'origen' => $p->origen,
            'fechaOferta' => optional($p->fecha_oferta)->toDateString(),
            'createdBy' => $p->creator?->name,
            'quoteCurrency' => $p->quote_currency ?? 'USD',
            'materialCost' => $p->material_cost,
            'laborCost' => $p->labor_cost,
            'totalCost' => $p->total_cost,
            'negotiatedAdvancePercent' => $p->negotiated_advance_percent,
            'deliveryWeeks' => $p->delivery_weeks,
            'precioAnterior' => $p->precio_anterior,
            'precioNuevo' => $p->precio_nuevo,
            'diferencia' => $p->diferencia,
            'motivo' => $p->motivo,
            'isAwarded' => $p->id === $project->selected_proposal_id,
            'replacedById' => $p->replaced_by_id,
            'isRemoved' => $p->trashed(),
            'items' => collect($p->material_items ?? [])->map(fn ($i) => [
                'materialName' => $i['materialName'] ?? null,
                'quantity' => $i['quantity'] ?? null,
                'unitPrice' => $i['unitPrice'] ?? null,
                'totalPrice' => $i['totalPrice'] ?? null,
                'catalogProductId' => $i['catalogProductId'] ?? null,
            ])->values()->all(),
        ])->values()->all();
    }

    private function award(Project $project, $awarded): ?array
    {
        if (!$awarded) {
            return null;
        }

        return [
            'proposalId' => $awarded->id,
            'contractorCode' => $awarded->contractor_code,
            'contractorName' => $awarded->contractor_name_snapshot ?? $awarded->contractor?->name,
            'totalCost' => $awarded->total_cost,
            'negotiatedAdvancePercent' => $awarded->negotiated_advance_percent,
            'origen' => $awarded->origen,
            'rateFreezes' => $project->rateFreezes->whereNull('superseded_by_id')->map(fn ($f) => [
                'trigger' => $f->trigger,
                'baseCurrency' => $f->base_currency,
                'frozenRate' => $f->frozen_rate,
                'frozenAmountBase' => $f->frozen_amount_base,
                'frozenAt' => optional($f->frozen_at)->toIso8601String(),
                'source' => $f->source,
            ])->values()->all(),
        ];
    }

    private function payments(Project $project, ?float $awarded): array
    {
        $items = $project->payments->sortBy('paid_date')->map(fn ($p) => [
            'id' => $p->id,
            'type' => $p->payment_type,
            'amount' => $p->amount,
            'currency' => $p->currency,
            'paidDate' => optional($p->paid_date)->format('Y-m-d'),
            'bank' => $p->bank,
            'reference' => $p->reference,
            'notes' => $p->notes,
            'proposalId' => $p->proposal_id,
            'proof' => $p->comprobante ? ['id' => $p->comprobante->id, 'name' => $p->comprobante->original_name] : null,
        ])->values();
        $total = round((float) $items->sum('amount'), 2);

        return [
            'items' => $items->all(),
            'total' => $total,
            'percentOfAwarded' => ($awarded !== null && $awarded > 0) ? round($total / $awarded * 100, 2) : null,
            'withoutProof' => $items->whereNull('proof')->count(),
        ];
    }

    /** Planos/cálculos/correcciones agrupados por documento lógico, con todas sus versiones. */
    private function drawings(Project $project): array
    {
        return ProjectDocument::withTrashed()
            ->where('project_id', $project->id)
            ->whereIn('document_type', self::DRAWING_TYPES)
            ->with('uploader:id,name')
            ->orderBy('version_number')
            ->get()
            ->groupBy('document_group_id')
            ->map(fn ($versions) => [
                'groupId' => $versions->first()->document_group_id,
                'type' => $versions->first()->document_type,
                'name' => $versions->last()->original_name,
                'currentVersion' => $versions->whereNull('deleted_at')->max('version_number'),
                'versions' => $versions->map(fn ($d) => [
                    'id' => $d->id,
                    'version' => $d->version_number,
                    'name' => $d->original_name,
                    'uploadedBy' => $d->uploader?->name,
                    'uploadedAt' => optional($d->created_at)->toIso8601String(),
                    'isDeleted' => $d->trashed(),
                ])->values()->all(),
            ])->values()->all();
    }

    private function closure(Project $project): array
    {
        return [
            'isClosed' => $project->status === 'COMPLETADO_PAGADO',
            'qualityVerified' => (bool) $project->quality_verified,
            'completionVerifiedDate' => optional($project->completion_verified_date)->format('Y-m-d'),
            'reevaluations' => ProjectDocument::where('project_id', $project->id)->where('document_type', 'REEVALUACION')->count(),
        ];
    }
}
