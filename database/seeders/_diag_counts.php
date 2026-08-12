<?php
// Diagnóstico temporal: conteo por tabla. Se elimina al cerrar el diagnóstico.
$tables = [
    'projects', 'contractors', 'project_proposals', 'project_materials',
    'project_payments', 'project_documents', 'audit_logs', 'material_catalog',
    'users', 'supplier_material_proposals', 'supplier_invitations',
];
foreach ($tables as $t) {
    try {
        echo $t . ': ' . DB::table($t)->count() . PHP_EOL;
    } catch (\Throwable $e) {
        echo $t . ': ERROR ' . $e->getMessage() . PHP_EOL;
    }
}