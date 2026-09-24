<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Histórico de Obras (Presidencia): obra → presupuesto → solicitud →
 * proveedores → adjudicación → pagos → planos → cierre, con estimado vs
 * aprobado vs ejecutado. El listado agrega en SQL (sin cargar todas las
 * obras); el detalle arma la cadena de una sola obra vía ProjectHistoryDetailBuilder.
 */
class ProjectHistoryService
{
    /** Tope de filas de la exportación: evita respuestas gigantes ante un portafolio enorme. */
    public const EXPORT_LIMIT = 2000;

    private const EXECUTED_SQL = '(select coalesce(sum(pp.amount), 0) from project_payments pp where pp.project_id = projects.id)';
    private const AWARDED_SQL = '(select sp.total_cost from project_proposals sp where sp.id = projects.selected_proposal_id)';
    private const CONTRACTOR_NAME_SQL = '(select sp.contractor_name_snapshot from project_proposals sp where sp.id = projects.selected_proposal_id)';
    private const CONTRACTOR_RATING_SQL = '(select c.rating from contractors c where c.code = projects.selected_contractor_code)';

    public function __construct(private ProjectHistoryDetailBuilder $detailBuilder) {}

    /**
     * @param array{status?: ?string, type?: ?string, q?: ?string, dateFrom?: ?string, dateTo?: ?string, withAlerts?: bool} $filters
     */
    public function getList(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage);
    }

    /** Mismo filtrado que getList, sin paginar y con tope, para exportar a CSV/Excel/PDF. */
    public function getExportRows(array $filters): Collection
    {
        return $this->query($filters)->limit(self::EXPORT_LIMIT)->get();
    }

    public function getDetail(Project $project): array
    {
        return $this->detailBuilder->build($project);
    }

    private function query(array $filters): Builder
    {
        $executed = self::EXECUTED_SQL;
        $awarded = self::AWARDED_SQL;

        return Project::query()
            ->select('projects.*')
            ->selectRaw("{$executed} as executed_total")
            ->selectRaw("{$awarded} as awarded_total")
            ->selectRaw(self::CONTRACTOR_NAME_SQL . ' as awarded_contractor_name')
            ->selectRaw(self::CONTRACTOR_RATING_SQL . ' as awarded_contractor_rating')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('projects.status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('projects.type', $v))
            ->when($filters['q'] ?? null, function ($q, $v) {
                // ESCAPE explícito ('!'): el escape por defecto con "\" solo existe en MySQL.
                $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $v) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw("projects.id like ? escape '!'", [$like])
                    ->orWhereRaw("projects.title like ? escape '!'", [$like])
                    ->orWhereRaw("projects.location like ? escape '!'", [$like]));
            })
            ->when($filters['dateFrom'] ?? null, fn ($q, $v) => $q->whereDate('projects.created_date', '>=', $v))
            ->when($filters['dateTo'] ?? null, fn ($q, $v) => $q->whereDate('projects.created_date', '<=', $v))
            ->when(!empty($filters['withAlerts']), fn ($q) => $q->whereRaw(
                "((projects.approved_investment_amount is not null and ({$executed} > projects.approved_investment_amount or coalesce({$awarded}, 0) > projects.approved_investment_amount))
                or ({$awarded} is not null and {$executed} > {$awarded}))"
            ))
            ->orderByDesc('projects.created_date')
            ->orderByDesc('projects.id');
    }
}
