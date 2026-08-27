<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray($request): array
    {
        $advance = $this->payments->firstWhere('payment_type', 'ADVANCE');
        $final = $this->payments->firstWhere('payment_type', 'FINAL');

        $contractorRatings = \App\Models\Contractor::whereIn(
            'code',
            $this->proposals->pluck('contractor_code')
        )->pluck('rating', 'code');

        $proposalCreatorNames = \App\Models\User::whereIn(
            'id',
            $this->proposals->pluck('created_by')->filter()
        )->pluck('name', 'id');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'description' => $this->description,
            'location' => $this->location,
            'createdDate' => optional($this->created_date)->format('Y-m-d'),
            'createdAt' => optional($this->created_at)->toIso8601String(),
            'updatedAt' => optional($this->updated_at)->toIso8601String(),
            'status' => $this->status,
            'materials' => $this->materials->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'estimatedUnitPrice' => $item->estimated_unit_price,
                'condition' => $item->condition,
                'warrantyValue' => $item->warranty_value,
                'warrantyUnit' => $item->warranty_unit,
                'brand' => $item->brand,
                'model' => $item->model,
                'specifications' => $item->specifications,
                'observations' => $item->observations,
            ])->values(),
            'estimatedTotal' => $this->estimated_total,
            'cierreObraNotes' => $this->cierre_obra_notes,
            'calculationsAdded' => $this->calculations_added,
            'blueprintsCount' => $this->blueprints_count,
            'dossierAiScore' => $this->dossier_ai_score,
            'dossierAiSummary' => $this->dossier_ai_summary,
            'dossierAiAlerts' => $this->dossier_ai_alerts,
            'dossierAiRecommendation' => $this->dossier_ai_recommendation,
            'dossierAiSuggestedAmount' => $this->dossier_ai_suggested_amount,
            'dossierAiCompletenessFactors' => $this->dossier_ai_completeness_factors,
            'dossierAiProvider' => $this->dossier_ai_provider,
            'dossierAiEvaluatedAt' => optional($this->dossier_ai_evaluated_at)->toIso8601String(),
            'procuraReviewNotes' => $this->procura_review_notes,
            'approvedInvestmentAmount' => $this->approved_investment_amount,
            // Excluye propuestas ya renegociadas (replaced_by_id != null) del
            // cuadro comparativo activo — siguen existiendo en la base de
            // datos (nunca se borran) y quedan disponibles para auditoría vía
            // AuditLog, no se pierden ni se muestran acá como "vigentes".
            'proposals' => $this->proposals->whereNull('replaced_by_id')->map(fn ($proposal) => [
                'id' => $proposal->id,
                'contractorCode' => $proposal->contractor_code,
                'contractorName' => $proposal->contractor_name_snapshot,
                'contractorRating' => $contractorRatings[$proposal->contractor_code] ?? null,
                'materialCost' => $proposal->material_cost,
                'materialItems' => $proposal->material_items,
                'laborCost' => $proposal->labor_cost,
                'totalCost' => $proposal->total_cost,
                'deliveryWeeks' => $proposal->delivery_weeks,
                'durationValue' => $proposal->duration_value,
                'durationUnit' => $proposal->duration_unit,
                'negotiatedAdvancePercent' => $proposal->negotiated_advance_percent,
                'description' => $proposal->description,
                'origen' => $proposal->origen,
                'fechaOferta' => optional($proposal->fecha_oferta)->toDateString(),
                'creadoPor' => $proposalCreatorNames[$proposal->created_by] ?? null,
                'precioAnterior' => $proposal->precio_anterior,
                'precioNuevo' => $proposal->precio_nuevo,
                'diferencia' => $proposal->diferencia,
                'motivo' => $proposal->motivo,
                'motivoAnticipoExcedido' => $proposal->motivo_anticipo_excedido,
            ])->values(),
            'selectedContractorCode' => $this->selected_contractor_code,
            'selectedProposalId' => $this->selected_proposal_id,
            'advancePaidAmount' => $advance?->amount,
            'advancePaidDate' => optional($advance?->paid_date)->format('Y-m-d'),
            'finalPaidAmount' => $final?->amount,
            'finalPaidDate' => optional($final?->paid_date)->format('Y-m-d'),
            'qualityVerified' => $this->quality_verified,
            'completionVerifiedDate' => optional($this->completion_verified_date)->format('Y-m-d'),
            'documents' => $this->whenLoaded('documents', fn () =>
                ProjectDocumentResource::collection($this->documents)
            ),
        ];
    }
}
