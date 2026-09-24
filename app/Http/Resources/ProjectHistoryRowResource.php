<?php

namespace App\Http\Resources;

use App\Support\ProjectFigures;
use Illuminate\Http\Resources\Json\JsonResource;

/** Fila del listado del Histórico de Obras (requiere los selectRaw de ProjectHistoryService::query). */
class ProjectHistoryRowResource extends JsonResource
{
    public function toArray($request): array
    {
        $awarded = $this->awarded_total !== null ? (float) $this->awarded_total : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'location' => $this->location,
            'status' => $this->status,
            'createdDate' => optional($this->created_date)->format('Y-m-d'),
            'contractor' => $this->selected_contractor_code ? [
                'code' => $this->selected_contractor_code,
                'name' => $this->awarded_contractor_name,
                'rating' => $this->awarded_contractor_rating !== null ? (float) $this->awarded_contractor_rating : null,
            ] : null,
            'figures' => ProjectFigures::build(
                $this->estimated_total !== null ? (float) $this->estimated_total : null,
                $this->approved_investment_amount !== null ? (float) $this->approved_investment_amount : null,
                $awarded,
                (float) $this->executed_total,
            ),
        ];
    }
}
