<?php

namespace App\Support;

/**
 * Cifras financieras de una obra para el Histórico de Obras, en moneda base
 * (USD). Fuente única de las definiciones acordadas:
 *  - estimado   = projects.estimated_total
 *  - aprobado   = projects.approved_investment_amount (null => sin aprobar, sin fallback)
 *  - adjudicado = total_cost de la propuesta ganadora
 *  - ejecutado  = suma de project_payments.amount
 */
class ProjectFigures
{
    public static function build(?float $estimated, ?float $approved, ?float $awarded, float $executed): array
    {
        $pct = fn (?float $value, ?float $base) => ($value !== null && $base !== null && $base > 0)
            ? round(($value / $base - 1) * 100, 2)
            : null;

        return [
            'estimated' => $estimated,
            'approved' => $approved,
            'awarded' => $awarded,
            'executed' => round($executed, 2),
            'executionPercent' => ($approved !== null && $approved > 0) ? round($executed / $approved * 100, 2) : null,
            'variation' => [
                'approvedVsEstimated' => $pct($approved, $estimated),
                'awardedVsApproved' => $pct($awarded, $approved),
                'executedVsAwarded' => $awarded !== null ? $pct($executed, $awarded) : null,
                'executedVsApproved' => $pct($executed, $approved),
            ],
            'flags' => [
                'unapproved' => $approved === null,
                'awardedExceedsApproved' => $approved !== null && $awarded !== null && $awarded > $approved,
                'executedExceedsAwarded' => $awarded !== null && $executed > $awarded,
                'executedExceedsApproved' => $approved !== null && $executed > $approved,
            ],
        ];
    }
}
