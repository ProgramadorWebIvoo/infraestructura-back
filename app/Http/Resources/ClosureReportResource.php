<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Informe de cierre. En el enlace público (atributo `closure_public`) se
 * omiten las notas internas de residente/Auditoría y las rutas de fotos
 * apuntan al endpoint del token en vez del autenticado.
 */
class ClosureReportResource extends JsonResource
{
    public function toArray($request): array
    {
        $public = (bool) $request->attributes->get('closure_public', false);
        $photoBase = $public ? "public/closures/{$this->id}/photos" : "projects/{$this->project_id}/closure-report/photos";

        $data = [
            'id' => $this->id,
            'projectId' => $this->project_id,
            'status' => $this->status,
            'revision' => $this->revision,
            'contractorNotes' => $this->contractor_notes,
            'submittedAt' => optional($this->submitted_at)->toIso8601String(),
            'rejectionReason' => $this->rejection_reason,
            'rejectedByRole' => $this->rejected_by_role,
            'items' => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'unit' => $i->unit,
                'contractedQuantity' => $i->contracted_quantity,
                'executedQuantity' => $i->executed_quantity,
                'unitPriceUsd' => $public ? null : $i->unit_price_usd,
                'note' => $i->note,
            ])->values(),
            'photos' => $this->photos->map(fn ($p) => [
                'id' => $p->id,
                'itemId' => $p->item_id,
                'uploadedByType' => $p->uploaded_by_type,
                'originalName' => $p->original_name,
                'path' => "{$photoBase}/{$p->id}",
            ])->values(),
        ];

        if ($public) {
            return $data + [
                'project' => ['id' => $this->project->id, 'title' => $this->project->title, 'location' => $this->project->location],
                'editable' => $this->isEditableByContractor() && $this->project->status === 'EN_EJECUCION',
            ];
        }

        return $data + [
            'contractorCode' => $this->contractor_code,
            'residentUserId' => $this->resident_user_id,
            'residentNotes' => $this->resident_notes,
            'residentVerifiedAt' => optional($this->resident_verified_at)->toIso8601String(),
            'auditNotes' => $this->audit_notes,
            'auditVerifiedAt' => optional($this->audit_verified_at)->toIso8601String(),
            'finiquitoAmount' => $this->finiquito_amount,
        ];
    }
}
