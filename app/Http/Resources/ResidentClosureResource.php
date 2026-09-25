<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Obra vista por su ingeniero residente (F2-R S1): partidas, cantidades y
 * fotos. Nunca precios, montos, finiquito ni notas de Auditoría.
 *
 * @property \App\Models\Project $resource
 */
class ResidentClosureResource extends JsonResource
{
    public function toArray($request): array
    {
        $report = $this->closureReport;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'location' => $this->location,
            'description' => $this->description,
            'status' => $this->status,
            'pendingAction' => $this->status === 'INFORME_ENVIADO',
            'closure' => $report ? [
                'status' => $report->status,
                'revision' => $report->revision,
                'contractorNotes' => $report->contractor_notes,
                'submittedAt' => optional($report->submitted_at)->toIso8601String(),
                'rejectionReason' => $report->rejection_reason,
                'residentNotes' => $report->resident_notes,
                'residentVerifiedAt' => optional($report->resident_verified_at)->toIso8601String(),
                'items' => $report->items->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'unit' => $i->unit,
                    'contractedQuantity' => $i->contracted_quantity,
                    'executedQuantity' => $i->executed_quantity,
                    'note' => $i->note,
                    'residentQuantity' => $i->resident_quantity,
                    'residentNote' => $i->resident_note,
                ])->values(),
                'photos' => $report->photos->map(fn ($p) => [
                    'id' => $p->id,
                    'itemId' => $p->item_id,
                    'uploadedByType' => $p->uploaded_by_type,
                    'originalName' => $p->original_name,
                    'path' => "resident/projects/{$this->id}/closure-report/photos/{$p->id}",
                ])->values(),
            ] : null,
        ];
    }
}
