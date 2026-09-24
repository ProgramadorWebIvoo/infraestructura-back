<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Histórico de Obras (Presidencia): obra → presupuesto → solicitud →
 * proveedores → adjudicación → pagos → planos → cierre, con estimado vs
 * aprobado vs ejecutado. El listado agrega en SQL (sin cargar todas las
 * obras); el detalle arma la cadena de una sola obra vía ProjectHistoryDetailBuilder.
 */
class ProjectHistoryService
{
    private const EXECUTED_SQL = '(select coalesce(sum(pp.amount), 0) from project_payments pp where pp.project_id = projects.id)';
    private const AWARDED_SQL = '(select sp.total_cost from project_proposals sp where sp.id = projects.selected_proposal_id)';

    public function __construct(private ProjectHistoryDetailBuilder $detailBuilder) {}

    /**
     * @param array{status?: ?string, type?: ?string, q?: ?string, dateFrom?: ?string, dateTo?: ?string, withAlerts?: bool} $filters
     */
    public function getList(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $executed = self::EXECUTED_SQL;
        $awarded = self::AWARDED_SQL;

        return Project::query()
            ->select('projects.*')
            ->selectRaw("{$executed} as executed_total")
            ->selectRaw("{$awarded} as awarded_total")
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('projects.status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('projects.type', $v))
            ->when($filters['q'] ?? null, function ($q, $v) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $v) . '%';
                $q->where(fn ($w) => $w->where('projects.id', 'like', $like)->orWhere('projects.title', 'like', $like));
            })
            ->when($filters['dateFrom'] ?? null, fn ($q, $v) => $q->whereDate('projects.created_date', '>=', $v))
            ->when($filters['dateTo'] ?? null, fn ($q, $v) => $q->whereDate('projects.created_date', '<=', $v))
            ->when(!empty($filters['withAlerts']), fn ($q) => $q->whereRaw(
                "((projects.approved_investment_amount is not null and ({$executed} > projects.approved_investment_amount or coalesce({$awarded}, 0) > projects.approved_investment_amount))
                or ({$awarded} is not null and {$executed} > {$awarded}))"
            ))
            ->orderByDesc('projects.created_date')
            ->orderByDesc('projects.id')
            ->paginate($perPage);
    }

    public function getDetail(Project $project): array
    {
        return $this->detailBuilder->build($project);
    }
}
