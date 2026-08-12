<?php

namespace App\Services;

use App\Models\Project;

/**
 * Agregados ejecutivos para el dashboard de PRESIDENCIA.
 *
 * Calcula server-side (sin paginación) para que los KPIs del dashboard sean
 * exactos aunque haya más obras de las que caben en la primera página de
 * GET /projects. Único punto de agregación del módulo.
 */
class DashboardSummaryService
{
    /** Estados posteriores a la adjudicación (contrato firmado, aún no cerrado). */
    private const COMMITTED_STATUSES = [
        'CONTRATADO',
        'EN_EJECUCION',
        'VERIFICANDO_FINALIZACION',
        'LISTO_PAGO_FINAL',
    ];

    /** Orden canónico del flujo, usado para ordenar el funnel. */
    private const STATUS_ORDER = [
        'CREADO'                => 0,
        'REVISADO_CIERRE'       => 1,
        'CONFIRMADO_PROCURA'    => 2,
        'COMPARATIVA_ENVIADA'   => 3,
        'CONTRATADO'            => 4,
        'EN_EJECUCION'          => 5,
        'VERIFICANDO_FINALIZACION' => 6,
        'LISTO_PAGO_FINAL'      => 7,
        'COMPLETADO_PAGADO'     => 8,
    ];

    /** Días sin actividad para marcar una obra como estancada. */
    private const STALLED_THRESHOLD_DAYS = 14;

    public function getSummary(): array
    {
        $projects = Project::with(['payments', 'proposals'])->get();

        $totalApproved = 0.0;
        $totalReleased = 0.0;
        $totalCommitted = 0.0;
        $funnel = [];
        $typeBreakdown = [];
        $locationBreakdown = [];
        $monthlyTrend = [];
        $topContractors = [];
        $stalled = [];
        $winningProposals = [];

        foreach ($projects as $project) {
            $status = $project->status;
            $approved = (float) ($project->approved_investment_amount ?? $project->estimated_total ?? 0);
            $released = (float) $project->payments->sum('amount');
            $winner = $project->proposals->firstWhere('id', $project->selected_proposal_id)
                ?? $project->proposals->firstWhere('contractor_code', $project->selected_contractor_code);

            $totalApproved += $approved;
            $totalReleased += $released;

            $funnel[$status]['count'] = ($funnel[$status]['count'] ?? 0) + 1;
            $funnel[$status]['approvedAmount'] = ($funnel[$status]['approvedAmount'] ?? 0) + $approved;

            $typeBreakdown[$project->type]['count'] = ($typeBreakdown[$project->type]['count'] ?? 0) + 1;
            $typeBreakdown[$project->type]['approvedAmount'] = ($typeBreakdown[$project->type]['approvedAmount'] ?? 0) + $approved;

            $location = $project->location ?: 'Sin ubicación';
            $locationBreakdown[$location]['count'] = ($locationBreakdown[$location]['count'] ?? 0) + 1;
            $locationBreakdown[$location]['approvedAmount'] = ($locationBreakdown[$location]['approvedAmount'] ?? 0) + $approved;

            $monthKey = optional($project->created_date)->format('Y-m') ?? 'Sin fecha';
            $monthlyTrend[$monthKey] = ($monthlyTrend[$monthKey] ?? 0) + 1;

            if ($winner) {
                $winningProposals[] = $winner;
                $winnerCost = (float) $winner->total_cost;

                $code = $winner->contractor_code;
                $topContractors[$code]['contractorName'] = $winner->contractor_name_snapshot;
                $topContractors[$code]['projectCount'] = ($topContractors[$code]['projectCount'] ?? 0) + 1;
                $topContractors[$code]['totalAmount'] = ($topContractors[$code]['totalAmount'] ?? 0) + $winnerCost;

                if (in_array($status, self::COMMITTED_STATUSES, true)) {
                    $totalCommitted += $winnerCost;
                }
                $funnel[$status]['committedAmount'] = ($funnel[$status]['committedAmount'] ?? 0) + $winnerCost;
            }

            // Obras sin actividad reciente (no cerradas): señal de riesgo ejecutivo.
            if ($status !== 'COMPLETADO_PAGADO') {
                $daysSinceUpdate = (int) now()->diffInDays($project->updated_at);
                if ($daysSinceUpdate >= self::STALLED_THRESHOLD_DAYS) {
                    $stalled[] = [
                        'id'              => $project->id,
                        'title'           => $project->title,
                        'status'          => $status,
                        'daysSinceUpdate' => $daysSinceUpdate,
                        'createdDate'     => optional($project->created_date)->format('Y-m-d'),
                    ];
                }
            }
        }

        // Funnel en orden canónico del flujo, incluyendo estados sin obras.
        $funnelEntries = [];
        foreach (self::STATUS_ORDER as $status => $_) {
            $funnelEntries[] = [
                'status'          => $status,
                'count'           => $funnel[$status]['count'] ?? 0,
                'approvedAmount'  => round($funnel[$status]['approvedAmount'] ?? 0, 2),
                'committedAmount' => round($funnel[$status]['committedAmount'] ?? 0, 2),
            ];
        }

        uasort($topContractors, fn ($a, $b) => $b['totalAmount'] <=> $a['totalAmount']);
        $topContractors = array_slice($topContractors, 0, 5, true);

        uasort($locationBreakdown, fn ($a, $b) => $b['approvedAmount'] <=> $a['approvedAmount']);
        $locationBreakdown = array_slice($locationBreakdown, 0, 8, true);

        ksort($monthlyTrend);

        usort($stalled, fn ($a, $b) => $b['daysSinceUpdate'] <=> $a['daysSinceUpdate']);
        $stalled = array_slice($stalled, 0, 10);

        $avgAdvance = $winningProposals ? collect($winningProposals)->avg('negotiated_advance_percent') : 0;
        $avgWeeks = $winningProposals ? collect($winningProposals)->avg('delivery_weeks') : 0;

        return [
            'totalProjects'          => $projects->count(),
            'totalApprovedInvestment'=> round($totalApproved, 2),
            'totalReleasedFunds'     => round($totalReleased, 2),
            'totalCommittedAmount'   => round($totalCommitted, 2),
            'pendingFunds'           => round(max(0, $totalApproved - $totalReleased), 2),
            'releasedPercent'        => $totalApproved > 0 ? round(($totalReleased / $totalApproved) * 100, 1) : 0,
            'excessReleased'         => round(max(0, $totalReleased - $totalApproved), 2),
            'funnel'                 => $funnelEntries,
            'typeBreakdown'          => array_map(
                fn ($type, $data) => [
                    'type'           => $type,
                    'count'          => $data['count'],
                    'approvedAmount' => round($data['approvedAmount'], 2),
                ],
                array_keys($typeBreakdown),
                array_values($typeBreakdown)
            ),
            'locationBreakdown'      => array_map(
                fn ($location, $data) => [
                    'location'       => $location,
                    'count'          => $data['count'],
                    'approvedAmount' => round($data['approvedAmount'], 2),
                ],
                array_keys($locationBreakdown),
                array_values($locationBreakdown)
            ),
            'monthlyTrend'           => array_map(
                fn ($month, $count) => ['month' => $month, 'count' => $count],
                array_keys($monthlyTrend),
                array_values($monthlyTrend)
            ),
            'topContractors'         => array_values(array_map(
                fn ($code, $data) => [
                    'contractorCode' => $code,
                    'contractorName' => $data['contractorName'],
                    'projectCount'   => $data['projectCount'],
                    'totalAmount'    => round($data['totalAmount'], 2),
                ],
                array_keys($topContractors),
                array_values($topContractors)
            )),
            'stalledProjects'        => array_values($stalled),
            'negotiationMetrics'     => [
                'avgAdvancePercent'  => round((float) $avgAdvance, 2),
                'avgDeliveryWeeks'   => round((float) $avgWeeks, 2),
            ],
            'updatedAt'              => now()->toIso8601String(),
        ];
    }
}
