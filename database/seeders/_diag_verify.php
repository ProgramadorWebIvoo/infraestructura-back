<?php
// Diagnóstico temporal: distribución por status + conteos. Se elimina al cerrar el diagnóstico.
$counts = [
    'projects' => DB::table('projects')->count(),
    'contractors' => DB::table('contractors')->count(),
    'project_proposals' => DB::table('project_proposals')->count(),
    'project_materials' => DB::table('project_materials')->count(),
    'project_payments' => DB::table('project_payments')->count(),
    'project_documents' => DB::table('project_documents')->count(),
    'audit_logs' => DB::table('audit_logs')->count(),
    'material_catalog' => DB::table('material_catalog')->count(),
    'users' => DB::table('users')->count(),
];
echo "=== CONTEO POR TABLA ===\n";
foreach ($counts as $t => $c) {
    echo str_pad($t, 25) . ': ' . $c . PHP_EOL;
}

echo "\n=== DISTRIBUCIÓN POR STATUS ===\n";
foreach (DB::table('projects')->selectRaw('status, count(*) as c, round(sum(estimated_total),0) as est, round(sum(coalesce(approved_investment_amount,0)),0) as appr')->groupBy('status')->orderBy('status')->get() as $r) {
    echo str_pad($r->status, 25) . ': ' . $r->c . ' obras | estimado ' . $r->est . ' | aprobado ' . $r->appr . PHP_EOL;
}

echo "\n=== PAGOS POR TIPO ===\n";
foreach (DB::table('project_payments')->selectRaw('payment_type, count(*) as c, round(sum(amount),0) as total')->groupBy('payment_type')->get() as $r) {
    echo str_pad($r->payment_type, 10) . ': ' . $r->c . ' pagos | total ' . $r->total . PHP_EOL;
}

echo "\n=== PROYECTOS ADJUDICADOS ===\n";
echo 'con selected_contractor_code: ' . DB::table('projects')->whereNotNull('selected_contractor_code')->count() . PHP_EOL;
echo 'con selected_proposal_id: ' . DB::table('projects')->whereNotNull('selected_proposal_id')->count() . PHP_EOL;

echo "\n=== PROPUESTAS POR PROYECTO (muestra) ===\n";
$proj = DB::table('projects')->whereNotNull('selected_proposal_id')->first();
if ($proj) {
    echo 'PRJ: ' . $proj->id . ' | status: ' . $proj->status . PHP_EOL;
    foreach (DB::table('project_proposals')->where('project_id', $proj->id)->get() as $p) {
        echo '  ' . $p->contractor_code . ' | total ' . $p->total_cost . ' | anticipo ' . $p->negotiated_advance_percent . '%' . ($p->id === $proj->selected_proposal_id ? ' [GANADORA]' : '') . PHP_EOL;
    }
    echo 'pagos:' . PHP_EOL;
    foreach (DB::table('project_payments')->where('project_id', $proj->id)->get() as $p) {
        echo '  ' . $p->payment_type . ' ' . $p->amount . ' (' . $p->paid_date . ')' . PHP_EOL;
    }
}

echo "\n=== ESTANCADAS (updated_at >= 14 días) POR STATUS ===\n";
$stalled = DB::table('projects')->selectRaw('status, count(*) as c')->where('status', '!=', 'COMPLETADO_PAGADO')->whereRaw('updated_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)')->groupBy('status')->get();
foreach ($stalled as $r) {
    echo str_pad($r->status, 25) . ': ' . $r->c . PHP_EOL;
}