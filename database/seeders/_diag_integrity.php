<?php
// Diagnóstico temporal: integridad de relaciones. Se elimina al cerrar el diagnóstico.
$done = DB::table('projects')->where('status', 'COMPLETADO_PAGADO')->first();
echo "=== PROYECTO COMPLETADO: " . $done->id . " ===\n";
echo 'title: ' . $done->title . "\n";
echo 'approved: ' . $done->approved_investment_amount . " | quality: " . var_export((bool) $done->quality_verified, true) . "\n";
echo 'winner: ' . $done->selected_contractor_code . " / " . $done->selected_proposal_id . "\n";
$proposals = DB::table('project_proposals')->where('project_id', $done->id)->get();
$winner = $proposals->firstWhere('id', $done->selected_proposal_id);
echo 'propuestas: ' . $proposals->count() . " | ganadora total: " . ($winner->total_cost ?? 'N/A') . "\n";
echo 'pagos:' . "\n";
foreach (DB::table('project_payments')->where('project_id', $done->id)->get() as $p) {
    echo '  ' . $p->payment_type . ' ' . $p->amount . ' (' . $p->paid_date . ')' . "\n";
}
$advance = DB::table('project_payments')->where('project_id', $done->id)->where('payment_type', 'ADVANCE')->first();
$final = DB::table('project_payments')->where('project_id', $done->id)->where('payment_type', 'FINAL')->first();
if ($advance && $final) {
    echo 'suma pagos: ' . ($advance->amount + $final->amount) . ' vs total ganadora: ' . ($winner->total_cost ?? 'N/A') . "\n";
}

echo "\n=== INTEGRIDAD FK (huérfanos) ===\n";
echo 'propuestas sin project: ' . DB::table('project_proposals as p')->leftJoin('projects as pr', 'pr.id', '=', 'p.project_id')->whereNull('pr.id')->count() . "\n";
echo 'pagos sin project: ' . DB::table('project_payments as p')->leftJoin('projects as pr', 'pr.id', '=', 'p.project_id')->whereNull('pr.id')->count() . "\n";
echo 'materials sin project: ' . DB::table('project_materials as m')->leftJoin('projects as pr', 'pr.id', '=', 'm.project_id')->whereNull('pr.id')->count() . "\n";
echo 'documents sin project: ' . DB::table('project_documents as d')->leftJoin('projects as pr', 'pr.id', '=', 'd.project_id')->whereNull('pr.id')->count() . "\n";
echo 'audit sin project: ' . DB::table('audit_logs as a')->leftJoin('projects as pr', 'pr.id', '=', 'a.project_id')->whereNull('pr.id')->count() . "\n";
echo 'proyectos con winner pero sin selected_contractor_code: ' . DB::table('projects as pr')->whereNotNull('pr.selected_proposal_id')->whereNull('pr.selected_contractor_code')->count() . "\n";

echo "\n=== CONTRATADO (sin pagos, correcto) ===\n";
$cnt = DB::table('projects as pr')->leftJoin('project_payments as p', 'p.project_id', '=', 'pr.id')->where('pr.status', 'CONTRATADO')->whereNotNull('p.id')->count();
echo 'contratados con pagos (debe ser 0): ' . $cnt . "\n";

echo "\n=== EJECUCIÓN (deben tener ADVANCE) ===\n";
$exec = DB::table('projects as pr')->leftJoin('project_payments as p', function ($j) {
    $j->on('p.project_id', '=', 'pr.id')->where('p.payment_type', '=', 'ADVANCE');
})->where('pr.status', 'EN_EJECUCION')->whereNull('p.id')->count();
echo 'en ejecución sin anticipo (debe ser 0): ' . $exec . "\n";